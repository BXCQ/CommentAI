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
     * 生成回复
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
     * 构建单条评论的消息数组
     */
    protected function buildMessages($commentText, $context)
    {
        $messages = array();

        $systemPrompt = $this->config->systemPrompt;
        if (!empty($systemPrompt)) {
            $messages[] = array(
                'role' => 'system',
                'content' => $systemPrompt
            );
        }

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

        if (in_array('article_title', $contextMode) && !empty($context['article_title'])) {
            $message .= "【文章标题】{$context['article_title']}\n\n";
        }

        if (in_array('article_excerpt', $contextMode) && !empty($context['article_excerpt'])) {
            $excerpt = mb_substr($context['article_excerpt'], 0, 300, 'UTF-8');
            $message .= "【文章摘要】{$excerpt}\n\n";
        }

        if (!empty($context['comment_chain']) && is_array($context['comment_chain'])) {
            $message .= "【对话历史】\n";
            foreach ($context['comment_chain'] as $chainItem) {
                $role = $chainItem['is_ai'] ? '博主（AI）' : $chainItem['author'];
                $message .= "{$role}：{$chainItem['text']}\n";
            }
            $message .= "\n";
        }

        $message .= "【读者评论】\n{$commentText}\n\n";
        $message .= "请以博主身份给出恰当的回复：";

        return $message;
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
