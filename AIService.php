<?php
/**
 * AI服务工厂 - 根据配置创建对应平台的 Provider
 *
 * @package CommentAI
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

require_once __DIR__ . '/providers/BaseProvider.php';
require_once __DIR__ . '/providers/OpenAIProvider.php';
require_once __DIR__ . '/providers/GeminiProvider.php';
require_once __DIR__ . '/providers/ClaudeProvider.php';

class CommentAI_AIService
{
    /**
     * 根据配置创建对应的 Provider 实例
     *
     * @param object $config 插件配置
     * @return CommentAI_BaseProvider
     * @throws Exception
     */
    public static function create($config)
    {
        $provider = $config->aiProvider;

        switch ($provider) {
            case 'aliyun':
            case 'openai':
            case 'deepseek':
            case 'kimi':
            case 'custom':
                return new CommentAI_OpenAIProvider($config);

            case 'gemini':
                return new CommentAI_GeminiProvider($config);

            case 'claude':
                return new CommentAI_ClaudeProvider($config);

            default:
                throw new Exception('未知的AI服务提供商: ' . $provider);
        }
    }
}
