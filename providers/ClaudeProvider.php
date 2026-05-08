<?php
/**
 * Anthropic Claude 原生 API 适配器
 *
 * @package CommentAI
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

require_once __DIR__ . '/BaseProvider.php';

class CommentAI_ClaudeProvider extends CommentAI_BaseProvider
{
    private $apiKey;
    private $modelName;
    private $apiEndpoint;

    public function __construct($config)
    {
        parent::__construct($config);
        $this->apiKey = $config->apiKey;
        $this->modelName = $config->modelName ?: 'claude-sonnet-4-20250514';
        $this->apiEndpoint = !empty($config->apiEndpoint)
            ? rtrim($config->apiEndpoint, '/')
            : 'https://api.anthropic.com';
    }

    /**
     * 发送消息
     */
    public function sendMessages($messages)
    {
        $url = $this->apiEndpoint . '/v1/messages';

        // 转换 messages 为 Claude 格式（system 分离）
        $claudeData = $this->convertMessages($messages);

        $requestBody = array(
            'model' => $this->modelName,
            'max_tokens' => intval($this->config->maxTokens ?: 300),
            'messages' => $claudeData['messages'],
        );

        if (!empty($claudeData['system'])) {
            $requestBody['system'] = $claudeData['system'];
        }

        // Claude 不支持 temperature 为 0 的某些场景，但通常支持
        $temperature = floatval($this->config->temperature ?: 0.7);
        if ($temperature > 0) {
            $requestBody['temperature'] = $temperature;
        }

        $headers = array(
            'Content-Type: application/json',
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: 2023-06-01'
        );

        $result = $this->httpPost($url, $headers, json_encode($requestBody));

        if ($result['code'] !== 200) {
            $errorInfo = json_decode($result['body'], true);
            $errorMessage = isset($errorInfo['error']['message'])
                ? $errorInfo['error']['message']
                : (isset($errorInfo['error']['type']) ? $errorInfo['error']['type'] : '未知错误');
            throw new Exception("Claude API请求失败 (HTTP {$result['code']}): {$errorMessage}");
        }

        return $this->parseResponse($result['body']);
    }

    /**
     * 转换标准 messages 为 Claude 格式
     * Claude 的 system prompt 是顶层字段，不在 messages 里
     */
    private function convertMessages($messages)
    {
        $claudeMessages = array();
        $system = null;

        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $system = $msg['content'];
            } elseif ($msg['role'] === 'user') {
                $claudeMessages[] = array(
                    'role' => 'user',
                    'content' => $msg['content']
                );
            } elseif ($msg['role'] === 'assistant') {
                $claudeMessages[] = array(
                    'role' => 'assistant',
                    'content' => $msg['content']
                );
            }
        }

        return array(
            'messages' => $claudeMessages,
            'system' => $system
        );
    }

    /**
     * 解析 Claude 响应
     */
    private function parseResponse($response)
    {
        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Claude JSON解析失败: ' . json_last_error_msg());
        }

        if (isset($data['content'][0]['text'])) {
            return trim($data['content'][0]['text']);
        }

        // 处理停止原因
        if (isset($data['stop_reason']) && $data['stop_reason'] === 'end_turn') {
            if (isset($data['content'][0]['text'])) {
                return trim($data['content'][0]['text']);
            }
        }

        throw new Exception('无法从 Claude 响应中提取回复内容: ' . $response);
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
                'message' => 'Claude 服务连接成功！',
                'reply' => $reply
            );
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'Claude 服务连接失败: ' . $e->getMessage(),
                'reply' => ''
            );
        }
    }
}
