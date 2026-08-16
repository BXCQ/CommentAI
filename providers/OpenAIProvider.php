<?php
/**
 * OpenAI 兼容接口适配器
 * 支持：OpenAI、阿里云百炼、DeepSeek、Kimi、智谱、豆包、硅基流动、
 *      OpenRouter、Groq、xAI、Ollama、自定义兼容接口
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
            case 'zhipu':
                return 'https://open.bigmodel.cn/api/paas/v4';
            case 'volcengine':
                return 'https://ark.cn-beijing.volces.com/api/v3';
            case 'siliconflow':
                return 'https://api.siliconflow.cn/v1';
            case 'openrouter':
                return 'https://openrouter.ai/api/v1';
            case 'groq':
                return 'https://api.groq.com/openai/v1';
            case 'xai':
                return 'https://api.x.ai/v1';
            case 'ollama':
                return 'http://127.0.0.1:11434/v1';
            case 'custom':
                throw new Exception('使用自定义接口时必须填写API地址');
            default:
                throw new Exception('未知的AI服务提供商: ' . $this->config->aiProvider);
        }
    }

    /**
     * GPT-5 / o-series 等推理模型不再接受 max_tokens
     */
    private function useMaxCompletionTokens()
    {
        if ($this->config->aiProvider === 'openai') {
            return true;
        }

        $model = strtolower($this->modelName ?: '');
        return (bool)preg_match('/^(gpt-5|o1|o3|o4)/', $model);
    }

    /**
     * 发送消息
     */
    public function sendMessages($messages)
    {
        $url = $this->apiEndpoint . '/chat/completions';
        $maxTokens = intval($this->config->maxTokens ?: 1024);

        $requestBody = array(
            'model' => $this->modelName,
            'messages' => $messages,
            'temperature' => floatval($this->config->temperature ?: 0.7),
            'stream' => false
        );

        if ($this->useMaxCompletionTokens()) {
            $requestBody['max_completion_tokens'] = $maxTokens;
        } else {
            $requestBody['max_tokens'] = $maxTokens;
        }

        $this->disableThinking($requestBody);

        $headers = array(
            'Content-Type: application/json'
        );

        if (!empty($this->apiKey)) {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        if ($this->config->aiProvider === 'openrouter') {
            $headers[] = 'HTTP-Referer: ' . Helper::options()->siteUrl;
            $headers[] = 'X-Title: CommentAI';
        }

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
     * 评论回复不需要深度思考：思考会占用 max_tokens，导致 content 为空
     */
    private function disableThinking(array &$requestBody)
    {
        $model = strtolower($this->modelName ?: '');

        if (preg_match('/qwen3|qwq|qwen-plus|qwen-flash|qwen-turbo|qwen-long/i', $model)) {
            $requestBody['enable_thinking'] = false;
        }

        if (preg_match('/glm-4\.5|glm-4\.6|glm-5|glm-4-plus/i', $model)) {
            $requestBody['thinking'] = array('type' => 'disabled');
        }

        if (preg_match('/^(gpt-5|o1|o3|o4)/', $model)) {
            $requestBody['reasoning_effort'] = 'low';
        }
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

        $choice = isset($data['choices'][0]) ? $data['choices'][0] : null;
        $message = ($choice && isset($choice['message'])) ? $choice['message'] : array();
        $content = $this->extractMessageContent($message);

        if ($content === '' && isset($data['output']['text'])) {
            $content = trim((string)$data['output']['text']);
        }

        if ($content === '' && isset($data['result']) && !is_array($data['result'])) {
            $content = trim((string)$data['result']);
        }

        if ($content !== '') {
            return $content;
        }

        $finishReason = '';
        if ($choice && !empty($choice['finish_reason'])) {
            $finishReason = $choice['finish_reason'];
        }

        $hasReasoning = !empty($message['reasoning_content']) || !empty($message['reasoning']);
        $hint = $hasReasoning || $finishReason === 'length'
            ? '思考/推理占用了输出额度，请关闭思考模式或增大「最大Token数」'
            : '请检查模型配置或增大「最大Token数」';

        throw new Exception(
            'AI返回空内容' .
            ($finishReason !== '' ? "（finish_reason={$finishReason}）" : '') .
            '，' . $hint
        );
    }

    /**
     * 从 message.content 提取纯文本（兼容空字符串、数组分片）
     */
    private function extractMessageContent($message)
    {
        if (!is_array($message) || !array_key_exists('content', $message)) {
            return '';
        }

        $content = $message['content'];
        if (is_string($content)) {
            return trim($content);
        }

        if (!is_array($content)) {
            return '';
        }

        $texts = array();
        foreach ($content as $part) {
            if (is_string($part)) {
                $texts[] = $part;
                continue;
            }
            if (!is_array($part)) {
                continue;
            }
            $type = isset($part['type']) ? $part['type'] : '';
            if (in_array($type, array('thinking', 'reasoning'), true)) {
                continue;
            }
            if (isset($part['text']) && is_string($part['text'])) {
                $texts[] = $part['text'];
            }
        }

        return trim(implode('', $texts));
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
