<?php
/**
 * OpenAI 兼容接口适配器
 * 支持：OpenAI、阿里云百炼、DeepSeek、Kimi、自定义兼容接口
 *
 * @package CommentAI
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

require_once __DIR__ . '/BaseProvider.php';

class CommentAI_OpenAIProvider extends CommentAI_BaseProvider
{
    private $apiEndpoint;
    private $apiKey;
    private $modelName;

    public function __construct($config)
    {
        parent::__construct($config);
        $this->apiKey = $config->apiKey;
        $this->modelName = $config->modelName;
        $this->apiEndpoint = $this->resolveEndpoint();
    }

    /**
     * 解析 API 端点
     */
    private function resolveEndpoint()
    {
        if (!empty($this->config->apiEndpoint)) {
            return rtrim($this->config->apiEndpoint, '/');
        }

        switch ($this->config->aiProvider) {
            case 'aliyun':
                return 'https://dashscope.aliyuncs.com/compatible-mode/v1';
            case 'openai':
                return 'https://api.openai.com/v1';
            case 'deepseek':
                return 'https://api.deepseek.com/v1';
            case 'kimi':
                return 'https://api.moonshot.cn/v1';
            case 'custom':
                throw new Exception('使用自定义接口时必须填写API地址');
            default:
                throw new Exception('未知的AI服务提供商: ' . $this->config->aiProvider);
        }
    }

    /**
     * 发送消息
     */
    public function sendMessages($messages)
    {
        $url = $this->apiEndpoint . '/chat/completions';

        $requestBody = array(
            'model' => $this->modelName,
            'messages' => $messages,
            'temperature' => floatval($this->config->temperature ?: 0.7),
            'max_tokens' => intval($this->config->maxTokens ?: 300),
            'stream' => false
        );

        $headers = array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey
        );

        $result = $this->httpPost($url, $headers, json_encode($requestBody));

        if ($result['code'] !== 200) {
            $errorInfo = json_decode($result['body'], true);
            $errorMessage = isset($errorInfo['error']['message'])
                ? $errorInfo['error']['message']
                : '未知错误';
            throw new Exception("API请求失败 (HTTP {$result['code']}): {$errorMessage}");
        }

        return $this->parseResponse($result['body']);
    }

    /**
     * 解析响应
     */
    private function parseResponse($response)
    {
        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON解析失败: ' . json_last_error_msg());
        }

        if (isset($data['choices'][0]['message']['content'])) {
            return trim($data['choices'][0]['message']['content']);
        }

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
     */
    public function testConnection()
    {
        try {
            $reply = $this->generateReply('你好，这是一条测试消息', array());
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
