<?php
/**
 * AI 服务提供者抽象基类
 *
 * @package CommentAI
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

abstract class CommentAI_BaseProvider
{
    protected $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    /**
     * 发送消息并获取回复
     *
     * @param array $messages 标准消息数组 [{role, content}, ...]
     * @return string AI 回复文本
     * @throws Exception
     */
    abstract public function sendMessages($messages);

    /**
     * 测试连接
     *
     * @return array ['success' => bool, 'message' => string, 'reply' => string]
     */
    abstract public function testConnection();

    /**
     * 生成回复（通用入口，构建 messages 并调用 sendMessages）
     *
     * @param string $commentText 评论内容
     * @param array $context 上下文信息
     * @return string AI 回复
     */
    public function generateReply($commentText, $context = array())
    {
        $messages = $this->buildMessages($commentText, $context);
        return $this->sendMessages($messages);
    }

    /**
     * 批量生成回复
     *
     * @param string $articleTitle 文章标题
     * @param string $articleExcerpt 文章摘要
     * @param array $comments 评论数组 [{coid, author, text, parent, chain}, ...]
     * @return array [{coid, reply}, ...]
     */
    public function generateBatchReplies($articleTitle, $articleExcerpt, $comments)
    {
        $messages = $this->buildBatchMessages($articleTitle, $articleExcerpt, $comments);
        $rawReply = $this->sendMessages($messages);
        return $this->parseBatchResponse($rawReply, $comments);
    }

    /**
     * 构建单条评论的消息数组
     */
    protected function buildMessages($commentText, $context)
    {
        $messages = array();

        // 系统提示词
        $systemPrompt = $this->config->systemPrompt;
        if (!empty($systemPrompt)) {
            $messages[] = array(
                'role' => 'system',
                'content' => $systemPrompt
            );
        }

        // 构建用户消息
        $userMessage = $this->buildUserMessage($commentText, $context);
        $messages[] = array(
            'role' => 'user',
            'content' => $userMessage
        );

        return $messages;
    }

    /**
     * 构建用户消息（含上下文和评论链）
     */
    protected function buildUserMessage($commentText, $context)
    {
        $contextMode = is_array($this->config->contextMode) ? $this->config->contextMode : array();
        $message = '';

        // 文章标题
        if (in_array('article_title', $contextMode) && !empty($context['article_title'])) {
            $message .= "【文章标题】{$context['article_title']}\n\n";
        }

        // 文章摘要
        if (in_array('article_excerpt', $contextMode) && !empty($context['article_excerpt'])) {
            $excerpt = mb_substr($context['article_excerpt'], 0, 300, 'UTF-8');
            $message .= "【文章摘要】{$excerpt}\n\n";
        }

        // 评论链（多轮对话历史）
        if (!empty($context['comment_chain']) && is_array($context['comment_chain'])) {
            $message .= "【对话历史】\n";
            foreach ($context['comment_chain'] as $chainItem) {
                $role = $chainItem['is_ai'] ? '博主（AI）' : $chainItem['author'];
                $message .= "{$role}：{$chainItem['text']}\n";
            }
            $message .= "\n";
        }

        // 当前评论
        $message .= "【读者评论】\n{$commentText}\n\n";
        $message .= "请以博主身份给出恰当的回复：";

        return $message;
    }

    /**
     * 构建批量消息（同一文章的多条评论）
     */
    protected function buildBatchMessages($articleTitle, $articleExcerpt, $comments)
    {
        $messages = array();

        $systemPrompt = $this->config->systemPrompt;
        if (!empty($systemPrompt)) {
            $messages[] = array(
                'role' => 'system',
                'content' => $systemPrompt
            );
        }

        $userMessage = '';
        $contextMode = is_array($this->config->contextMode) ? $this->config->contextMode : array();

        // 文章信息（只发一次）
        if (in_array('article_title', $contextMode) && !empty($articleTitle)) {
            $userMessage .= "【文章标题】{$articleTitle}\n\n";
        }

        if (in_array('article_excerpt', $contextMode) && !empty($articleExcerpt)) {
            $excerpt = mb_substr($articleExcerpt, 0, 300, 'UTF-8');
            $userMessage .= "【文章摘要】{$excerpt}\n\n";
        }

        // 多条评论
        $userMessage .= "以下是同一位读者在该文章下的多条评论，请为每条评论分别给出回复：\n\n";

        foreach ($comments as $i => $comment) {
            $num = $i + 1;
            $author = $comment['author'];

            // 评论链
            if (!empty($comment['chain']) && is_array($comment['chain'])) {
                foreach ($comment['chain'] as $chainItem) {
                    $role = $chainItem['is_ai'] ? '博主（AI）' : $chainItem['author'];
                    $userMessage .= "  历史：{$role}：{$chainItem['text']}\n";
                }
            }

            $userMessage .= "评论{$num}（来自 {$author}）：{$comment['text']}\n\n";
        }

        $coidList = array_map(function($c) { return $c['coid']; }, $comments);
        $userMessage .= "请以 JSON 数组格式返回每条评论的回复，coid 对应原评论ID：\n";
        $userMessage .= '[{"coid": ' . $coidList[0] . ', "reply": "回复内容"}';

        for ($i = 1; $i < count($coidList); $i++) {
            $userMessage .= ', {"coid": ' . $coidList[$i] . ', "reply": "回复内容"}';
        }

        $userMessage .= "]\n\n只返回 JSON 数组，不要其他内容。";

        $messages[] = array(
            'role' => 'user',
            'content' => $userMessage
        );

        return $messages;
    }

    /**
     * 解析批量响应
     */
    protected function parseBatchResponse($rawReply, $comments)
    {
        // 尝试提取 JSON
        $jsonStr = $rawReply;

        // 如果被 markdown 代码块包裹
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $rawReply, $matches)) {
            $jsonStr = $matches[1];
        }

        // 尝试直接解析
        $parsed = json_decode(trim($jsonStr), true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
            $results = array();
            foreach ($parsed as $item) {
                if (isset($item['coid']) && isset($item['reply'])) {
                    $results[] = array(
                        'coid' => intval($item['coid']),
                        'reply' => trim($item['reply'])
                    );
                }
            }
            if (!empty($results)) {
                return $results;
            }
        }

        // Fallback：把整段回复给第一条评论，其余返回空
        $results = array();
        foreach ($comments as $i => $comment) {
            $results[] = array(
                'coid' => $comment['coid'],
                'reply' => $i === 0 ? trim($rawReply) : ''
            );
        }
        return $results;
    }

    /**
     * 执行 HTTP POST 请求
     */
    protected function httpPost($url, $headers, $body, $timeout = 30)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception('CURL请求失败: ' . $error);
        }

        return array('code' => $httpCode, 'body' => $response);
    }
}
