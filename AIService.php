<?php
/**
 * AI服务抽象层 - 支持多平台
 * 
 * @package CommentAI
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class CommentAI_AIService
{
    private $config;
    private $provider;
    private $apiKey;
    private $apiEndpoint;
    private $modelName;

    public function __construct($config)
    {
        $this->config = $config;
        $this->provider = $config->aiProvider;
        $this->apiKey = $config->apiKey;
        $this->modelName = $config->modelName;
        
        // 设置API端点
        $this->apiEndpoint = $this->getApiEndpoint();
    }

    /**
     * 获取API端点
     */
    private function getApiEndpoint()
    {
        // 如果用户自定义了端点，使用自定义的
        if (!empty($this->config->apiEndpoint)) {
            return rtrim($this->config->apiEndpoint, '/');
        }

        // 根据提供商返回默认端点
        switch ($this->provider) {
            case 'aliyun':
                return 'https://dashscope.aliyuncs.com/compatible-mode/v1';
            case 'openai':
                return 'https://api.openai.com/v1';
            case 'deepseek':
                return 'https://api.deepseek.com/v1';
            case 'custom':
                throw new Exception('使用自定义接口时必须填写API地址');
            default:
                throw new Exception('未知的AI服务提供商: ' . $this->provider);
        }
    }

    /**
     * 生成AI回复
     * 
     * @param string $commentText 评论内容
     * @param array $context 上下文信息（文章标题、摘要、父评论等）
     * @return string AI生成的回复
     */
    public function generateReply($commentText, $context = array())
    {
        // 构建消息
        $messages = $this->buildMessages($commentText, $context);
        
        // 调用API
        $response = $this->callAPI($messages);
        
        // 解析响应
        return $this->parseResponse($response);
    }

    /**
     * 构建消息数组
     */
    private function buildMessages($commentText, $context)
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

        // 构建用户消息（包含上下文）
        $userMessage = $this->buildUserMessage($commentText, $context);
        $messages[] = array(
            'role' => 'user',
            'content' => $userMessage
        );

        return $messages;
    }

    /**
     * 构建用户消息（包含上下文信息）
     */
    private function buildUserMessage($commentText, $context)
    {
        $contextMode = $this->config->contextMode ? $this->config->contextMode : array();
        $message = '';

        // 添加文章信息
        if (in_array('article_title', $contextMode) && !empty($context['article_title'])) {
            $message .= "【文章标题】{$context['article_title']}\n\n";
        }

        if (in_array('article_excerpt', $contextMode) && !empty($context['article_excerpt'])) {
            $excerpt = mb_substr($context['article_excerpt'], 0, 300, 'UTF-8');
            $message .= "【文章摘要】{$excerpt}\n\n";
        }

        // 添加父评论
        if (in_array('parent_comment', $contextMode) && !empty($context['parent_comment'])) {
            $message .= "【正在回复的评论】\n{$context['parent_comment']}\n\n";
        }

        // 添加当前评论
        $message .= "【读者评论】\n{$commentText}\n\n";
        $message .= "请以博主身份给出恰当的回复：";

        return $message;
    }

    /**
     * 调用API
     */
    private function callAPI($messages)
    {
        $url = $this->apiEndpoint . '/chat/completions';
        
        // 构建请求体
        $requestBody = array(
            'model' => $this->modelName,
            'messages' => $messages,
            'temperature' => floatval($this->config->temperature ?: 0.7),
            'max_tokens' => intval($this->config->maxTokens ?: 300),
            'stream' => false
        );

        // 构建请求头
        $headers = array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey
        );

        // 阿里云特殊处理
        if ($this->provider == 'aliyun') {
            $headers = array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey
            );
        }

        // 发送请求
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // 生产环境建议启用
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception('CURL请求失败: ' . $error);
        }

        if ($httpCode !== 200) {
            $errorInfo = json_decode($response, true);
            $errorMessage = isset($errorInfo['error']['message']) 
                ? $errorInfo['error']['message'] 
                : '未知错误';
            throw new Exception("API请求失败 (HTTP {$httpCode}): {$errorMessage}");
        }

        return $response;
    }

    /**
     * 解析API响应
     */
    private function parseResponse($response)
    {
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON解析失败: ' . json_last_error_msg());
        }

        // 标准OpenAI响应格式
        if (isset($data['choices'][0]['message']['content'])) {
            return trim($data['choices'][0]['message']['content']);
        }

        // 尝试其他可能的响应格式
        if (isset($data['output']['text'])) {
            return trim($data['output']['text']);
        }

        if (isset($data['result'])) {
            return trim($data['result']);
        }

        throw new Exception('无法从响应中提取AI回复内容: ' . $response);
    }

    /**
     * 测试连接
     * 
     * @return array ['success' => bool, 'message' => string, 'reply' => string]
     */
    public function testConnection()
    {
        try {
            $testMessage = '你好，这是一条测试消息';
            $reply = $this->generateReply($testMessage, array());
            
            return array(
                'success' => true,
                'message' => 'AI服务连接成功！',
                'reply' => $reply
            );
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'AI服务连接失败: ' . $e->getMessage(),
                'reply' => ''
            );
        }
    }
}
