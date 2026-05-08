<?php
/**
 * Google Gemini 原生 API 适配器
 *
 * @package CommentAI
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

require_once __DIR__ . '/BaseProvider.php';

class CommentAI_GeminiProvider extends CommentAI_BaseProvider
{
    private $apiKey;
    private $modelName;

    public function __construct($config)
    {
        parent::__construct($config);
        $this->apiKey = $config->apiKey;
        $this->modelName = $config->modelName ?: 'gemini-2.0-flash';
    }

    /**
     * 发送消息
     */
    public function sendMessages($messages)
    {
        $endpoint = $this->config->apiEndpoint;
        if (empty($endpoint)) {
            $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . $this->modelName . ':generateContent';
        }

        $url = $endpoint . '?key=' . $this->apiKey;

        // 转换 messages 为 Gemini 格式
        $geminiMessages = $this->convertMessages($messages);

        $requestBody = array(
            'contents' => $geminiMessages['contents'],
            'generationConfig' => array(
                'temperature' => floatval($this->config->temperature ?: 0.7),
                'maxOutputTokens' => intval($this->config->maxTokens ?: 300),
            )
        );

        // Gemini 的 systemInstruction 是单独字段
        if (!empty($geminiMessages['systemInstruction'])) {
            $requestBody['systemInstruction'] = $geminiMessages['systemInstruction'];
        }

        $headers = array(
            'Content-Type: application/json'
        );

        $result = $this->httpPost($url, $headers, json_encode($requestBody));

        if ($result['code'] !== 200) {
            $errorInfo = json_decode($result['body'], true);
            $errorMessage = isset($errorInfo['error']['message'])
                ? $errorInfo['error']['message']
                : '未知错误';
            throw new Exception("Gemini API请求失败 (HTTP {$result['code']}): {$errorMessage}");
        }

        return $this->parseResponse($result['body']);
    }

    /**
     * 转换标准 messages 为 Gemini 格式
     */
    private function convertMessages($messages)
    {
        $contents = array();
        $systemInstruction = null;

        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                // Gemini 的 system prompt 单独传
                $systemInstruction = array(
                    'parts' => array(array('text' => $msg['content']))
                );
            } elseif ($msg['role'] === 'user') {
                $contents[] = array(
                    'role' => 'user',
                    'parts' => array(array('text' => $msg['content']))
                );
            } elseif ($msg['role'] === 'assistant') {
                $contents[] = array(
                    'role' => 'model',
                    'parts' => array(array('text' => $msg['content']))
                );
            }
        }

        return array(
            'contents' => $contents,
            'systemInstruction' => $systemInstruction
        );
    }

    /**
     * 解析 Gemini 响应
     */
    private function parseResponse($response)
    {
        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Gemini JSON解析失败: ' . json_last_error_msg());
        }

        if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            return trim($data['candidates'][0]['content']['parts'][0]['text']);
        }

        // 处理安全过滤导致的空响应
        if (isset($data['candidates'][0]['finishReason']) && $data['candidates'][0]['finishReason'] === 'SAFETY') {
            throw new Exception('Gemini 内容被安全过滤器拦截');
        }

        throw new Exception('无法从 Gemini 响应中提取回复内容: ' . $response);
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
                'message' => 'Gemini 服务连接成功！',
                'reply' => $reply
            );
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'Gemini 服务连接失败: ' . $e->getMessage(),
                'reply' => ''
            );
        }
    }
}
