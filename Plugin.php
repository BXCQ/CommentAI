<?php
/**
 * AI 智能评论回复插件
 * 
 * @package CommentAI
 * @author 璇
 * @version 1.3.0
 * @link https://github.com/BXCQ/CommentAI
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class CommentAI_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法
     */
    public static function activate()
    {
        // 创建AI回复队列表
        self::createTable();
        
        // 注册评论提交后的钩子
        Typecho_Plugin::factory('Widget_Feedback')->finishComment = array('CommentAI_Plugin', 'onCommentSubmit');
        
        // 注册后台管理面板
        Helper::addPanel(3, 'CommentAI/panel.php', 'AI评论回复', 'AI评论回复管理', 'administrator');
        Helper::addAction('comment-ai', 'CommentAI_Action');
        
        return _t('插件已激活，请进入设置页面配置 AI 服务');
    }

    /**
     * 禁用插件方法
     */
    public static function deactivate()
    {
        Helper::removePanel(3, 'CommentAI/panel.php');
        Helper::removeAction('comment-ai');
    }

    /**
     * 获取插件配置面板
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        
        // === 基础配置 ===
        $basicTitle = new Typecho_Widget_Helper_Layout();
        $basicTitle->html('<h3 style="border-bottom:2px solid #467b96;padding-bottom:5px;">⚙️ 基础配置</h3>');
        $form->addItem($basicTitle);

        $enablePlugin = new Typecho_Widget_Helper_Form_Element_Radio(
            'enablePlugin',
            array(
                '1' => '启用',
                '0' => '禁用（不处理任何评论）'
            ),
            '1',
            _t('插件开关'),
            _t('关闭后将不会对任何评论进行AI回复处理')
        );
        $form->addInput($enablePlugin);

        $replyMode = new Typecho_Widget_Helper_Form_Element_Radio(
            'replyMode',
            array(
                'auto' => '全自动模式（直接发布AI回复）',
                'audit' => '人工审核模式（生成后需后台审核）',
                'suggest' => '仅建议模式（仅显示建议，不发布）'
            ),
            'audit',
            _t('回复模式'),
            _t('选择AI生成回复后的处理方式')
        );
        $form->addInput($replyMode);

        $adminUid = new Typecho_Widget_Helper_Form_Element_Text(
            'adminUid',
            NULL,
            '1',
            _t('管理员UID'),
            _t('AI回复将以该用户身份发布（通常是博主的UID，默认为1）')
        );
        $form->addInput($adminUid);

        // === AI平台配置 ===
        $aiTitle = new Typecho_Widget_Helper_Layout();
        $aiTitle->html('<h3 style="border-bottom:2px solid #467b96;padding-bottom:5px;margin-top:30px;">🌐 AI平台配置</h3>');
        $form->addItem($aiTitle);

        $aiProvider = new Typecho_Widget_Helper_Form_Element_Select(
            'aiProvider',
            array(
                'aliyun' => '阿里云百炼（通义千问 Qwen）',
                'openai' => 'OpenAI（ChatGPT）',
                'deepseek' => 'DeepSeek',
                'kimi' => 'Kimi（月之暗面）',
                'gemini' => 'Google Gemini',
                'claude' => 'Anthropic Claude',
                'custom' => '自定义OpenAI兼容接口'
            ),
            'aliyun',
            _t('AI服务提供商'),
            _t('选择你使用的AI平台')
        );
        $form->addInput($aiProvider);

        $apiKey = new Typecho_Widget_Helper_Form_Element_Text(
            'apiKey',
            NULL,
            '',
            _t('API Key *'),
            _t('填入你的AI服务API密钥。<a href="https://bailian.console.aliyun.com/" target="_blank">阿里云</a> | <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI</a> | <a href="https://platform.deepseek.com/" target="_blank">DeepSeek</a> | <a href="https://platform.moonshot.cn/" target="_blank">Kimi</a> | <a href="https://aistudio.google.com/apikey" target="_blank">Gemini</a> | <a href="https://console.anthropic.com/" target="_blank">Claude</a>')
        );
        $apiKey->input->setAttribute('class', 'w-100');
        $form->addInput($apiKey->addRule('required', _t('API Key 不能为空')));

        $apiEndpoint = new Typecho_Widget_Helper_Form_Element_Text(
            'apiEndpoint',
            NULL,
            '',
            _t('API地址（可选）'),
            _t('自定义API端点，留空使用默认值。<br>阿里云：https://dashscope.aliyuncs.com/compatible-mode/v1<br>OpenAI：https://api.openai.com/v1<br>DeepSeek：https://api.deepseek.com/v1<br>Kimi：https://api.moonshot.cn/v1<br>Gemini：https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent<br>Claude：https://api.anthropic.com')
        );
        $apiEndpoint->input->setAttribute('class', 'w-100');
        $form->addInput($apiEndpoint);

        $modelName = new Typecho_Widget_Helper_Form_Element_Text(
            'modelName',
            NULL,
            'qwen-plus',
            _t('模型名称'),
            _t('填入模型标识，如：qwen-plus、gpt-4o-mini、deepseek-chat、moonshot-v1-8k、gemini-2.0-flash、claude-sonnet-4-20250514')
        );
        $form->addInput($modelName);

        // === Prompt 配置 ===
        $promptTitle = new Typecho_Widget_Helper_Layout();
        $promptTitle->html('<h3 style="border-bottom:2px solid #467b96;padding-bottom:5px;margin-top:30px;">💬 Prompt 配置</h3>');
        $form->addItem($promptTitle);

        $systemPrompt = new Typecho_Widget_Helper_Form_Element_Textarea(
            'systemPrompt',
            NULL,
            "你是一位友好、专业且富有人情味的博主。你的任务是根据读者的评论生成恰当的回复。\n\n回复要求：\n1. 语气自然、亲切，符合博主个人风格\n2. 针对评论内容给出有价值的回应\n3. 对提问要给出明确答案\n4. 对赞美要表示感谢并鼓励继续交流\n5. 对批评要理性对待并给出解释\n6. 回复长度控制在50-150字\n7. 使用中文回复（除非评论明确使用其他语言）\n8. 不要使用过于正式或机械化的表达",
            _t('系统提示词（System Prompt）'),
            _t('定义AI的角色和回复风格，支持多行输入')
        );
        $systemPrompt->input->setAttribute('rows', 8);
        $systemPrompt->input->setAttribute('class', 'w-100 mono');
        $form->addInput($systemPrompt);

        $contextMode = new Typecho_Widget_Helper_Form_Element_Checkbox(
            'contextMode',
            array(
                'article_title' => '包含文章标题',
                'article_excerpt' => '包含文章摘要（前300字）',
                'parent_comment' => '包含父级评论（如果是回复）'
            ),
            array('article_title', 'parent_comment'),
            _t('上下文信息'),
            _t('勾选后将把相关信息传递给AI，提升回复质量')
        );
        $form->addInput($contextMode);

        // === 高级配置 ===
        $advancedTitle = new Typecho_Widget_Helper_Layout();
        $advancedTitle->html('<h3 style="border-bottom:2px solid #467b96;padding-bottom:5px;margin-top:30px;">🔧 高级配置</h3>');
        $form->addItem($advancedTitle);

        $temperature = new Typecho_Widget_Helper_Form_Element_Text(
            'temperature',
            NULL,
            '0.7',
            _t('温度参数（Temperature）'),
            _t('控制回复的随机性，0-1之间，越高越随机。建议0.7-0.9')
        );
        $form->addInput($temperature);

        $maxTokens = new Typecho_Widget_Helper_Form_Element_Text(
            'maxTokens',
            NULL,
            '300',
            _t('最大Token数'),
            _t('单次回复的最大长度限制，建议200-500')
        );
        $form->addInput($maxTokens);

        $sensitiveWords = new Typecho_Widget_Helper_Form_Element_Textarea(
            'sensitiveWords',
            NULL,
            "政治\n暴力\n色情\n赌博",
            _t('敏感词过滤（每行一个）'),
            _t('如果AI生成的回复包含这些词，将被拦截不发布')
        );
        $sensitiveWords->input->setAttribute('rows', 5);
        $form->addInput($sensitiveWords);

        $rateLimit = new Typecho_Widget_Helper_Form_Element_Text(
            'rateLimit',
            NULL,
            '10',
            _t('每小时最大调用次数'),
            _t('防止API费用失控，0为不限制')
        );
        $form->addInput($rateLimit);

        $replyDelay = new Typecho_Widget_Helper_Form_Element_Text(
            'replyDelay',
            NULL,
            '0',
            _t('回复延迟（秒）'),
            _t('检测到评论后延迟多少秒再回复，0为立即回复。建议设置30-120秒，让回复更自然')
        );
        $form->addInput($replyDelay);

        $batchWindow = new Typecho_Widget_Helper_Form_Element_Text(
            'batchWindow',
            NULL,
            '1',
            _t('评论合并处理'),
            _t('同一游客在同一篇文章下的多条评论即时合并为一次API调用，节省token消耗。1为启用，0为禁用（逐条处理）')
        );
        $form->addInput($batchWindow);

        // === 低价值评论过滤 ===
        $lowValueTitle = new Typecho_Widget_Helper_Layout();
        $lowValueTitle->html('<h3 style="border-bottom:2px solid #467b96;padding-bottom:5px;margin-top:30px;">🔍 低价值评论过滤</h3>');
        $form->addItem($lowValueTitle);

        $lowValueDetection = new Typecho_Widget_Helper_Form_Element_Radio(
            'lowValueDetection',
            array(
                '1' => '启用',
                '0' => '禁用'
            ),
            '1',
            _t('低价值评论检测'),
            _t('识别"感谢"、"看看"、"1"等无实质内容的评论，减少不必要的API调用')
        );
        $form->addInput($lowValueDetection);

        $lowValueWords = new Typecho_Widget_Helper_Form_Element_Textarea(
            'lowValueWords',
            NULL,
            "1\n11\n666\n看看\n学习\n感谢\n感谢分享\n谢谢\n收藏了\n支持\nmark\n来了\n赞\n不错\n很好",
            _t('低价值关键词（每行一个）'),
            _t('评论内容完全匹配这些词时触发过滤')
        );
        $lowValueWords->input->setAttribute('rows', 6);
        $lowValueWords->input->setAttribute('class', 'w-100 mono');
        $form->addInput($lowValueWords);

        $lowValueMode = new Typecho_Widget_Helper_Form_Element_Radio(
            'lowValueMode',
            array(
                'skip' => '跳过（使用下方自定义回复，不调用AI）',
                'simplified' => '精简调用（只发送文章摘要给AI，生成简短回复）'
            ),
            'skip',
            _t('处理方式'),
            _t('识别到低价值评论后的处理策略')
        );
        $form->addInput($lowValueMode);

        $lowValueReply = new Typecho_Widget_Helper_Form_Element_Text(
            'lowValueReply',
            NULL,
            '感谢你的关注和支持！欢迎常来交流～',
            _t('跳过模式的固定回复'),
            _t('当选择"跳过"模式时，使用此固定回复内容。支持HTML标签')
        );
        $lowValueReply->input->setAttribute('class', 'w-100');
        $form->addInput($lowValueReply);

        // === 显示设置 ===
        $displayTitle = new Typecho_Widget_Helper_Layout();
        $displayTitle->html('<h3 style="border-bottom:2px solid #467b96;padding-bottom:5px;margin-top:30px;">🎨 显示设置</h3>');
        $form->addItem($displayTitle);

        $showAIBadge = new Typecho_Widget_Helper_Form_Element_Radio(
            'showAIBadge',
            array(
                '1' => '显示AI标识（如🤖 AI辅助回复）',
                '0' => '不显示（伪装成人工回复）'
            ),
            '1',
            _t('AI标识显示'),
            _t('出于透明性原则，建议显示AI标识')
        );
        $form->addInput($showAIBadge);

        $aiBadgeText = new Typecho_Widget_Helper_Form_Element_Text(
            'aiBadgeText',
            NULL,
            '🤖 AI辅助回复',
            _t('AI标识文本'),
            _t('当显示AI标识时，在回复后追加的文本')
        );
        $form->addInput($aiBadgeText);

        // === 触发条件 ===
        $triggerTitle = new Typecho_Widget_Helper_Layout();
        $triggerTitle->html('<h3 style="border-bottom:2px solid #467b96;padding-bottom:5px;margin-top:30px;">⚡ 触发条件</h3>');
        $form->addItem($triggerTitle);

        $triggerCondition = new Typecho_Widget_Helper_Form_Element_Checkbox(
            'triggerCondition',
            array(
                'approved_only' => '仅对已审核的评论回复',
                'no_spam' => '忽略垃圾评论',
                'no_trackback' => '忽略引用和trackback',
                'first_comment_only' => '仅对文章的第一条评论回复'
            ),
            array('approved_only', 'no_spam', 'no_trackback'),
            _t('触发条件过滤'),
            _t('勾选后将跳过不符合条件的评论')
        );
        $form->addInput($triggerCondition);
    }

    /**
     * 个人用户的配置面板
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    /**
     * 创建数据库表
     */
    private static function createTable()
    {
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $adapterName = $db->getAdapterName();
        
        // 表名
        $tableName = $prefix . 'comment_ai_queue';
        
        // 根据数据库类型创建表
        if ($adapterName == 'Mysql' || $adapterName == 'Mysqli' || strpos($adapterName, 'Pdo') !== false) {
            // MySQL 5.7+ 和 8.0+ 兼容
            $sql = "CREATE TABLE IF NOT EXISTS `{$tableName}` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `cid` INT UNSIGNED NOT NULL COMMENT '评论ID',
                `post_id` INT UNSIGNED NOT NULL COMMENT '文章ID',
                `comment_author` VARCHAR(255) NOT NULL COMMENT '评论者',
                `comment_text` TEXT NOT NULL COMMENT '评论内容',
                `ai_reply` TEXT NOT NULL COMMENT 'AI生成的回复',
                `status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT '状态',
                `created_at` INT UNSIGNED NOT NULL COMMENT '创建时间',
                `processed_at` INT UNSIGNED DEFAULT 0 COMMENT '处理时间',
                `error_msg` VARCHAR(500) DEFAULT NULL COMMENT '错误信息',
                PRIMARY KEY (`id`),
                KEY `idx_status` (`status`),
                KEY `idx_cid` (`cid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='AI评论回复队列';";
            
            $db->query($sql);
            
        } elseif ($adapterName == 'SQLite' || $adapterName == 'Pdo_SQLite') {
            // SQLite 需要分开执行
            $sqls = array(
                "CREATE TABLE IF NOT EXISTS '{$tableName}' (
                    'id' INTEGER PRIMARY KEY AUTOINCREMENT,
                    'cid' INTEGER NOT NULL,
                    'post_id' INTEGER NOT NULL,
                    'comment_author' TEXT NOT NULL,
                    'comment_text' TEXT NOT NULL,
                    'ai_reply' TEXT NOT NULL,
                    'status' TEXT NOT NULL DEFAULT 'pending',
                    'created_at' INTEGER NOT NULL,
                    'processed_at' INTEGER DEFAULT 0,
                    'error_msg' TEXT DEFAULT NULL
                );",
                "CREATE INDEX IF NOT EXISTS idx_status ON '{$tableName}' (status);",
                "CREATE INDEX IF NOT EXISTS idx_cid ON '{$tableName}' (cid);"
            );
            
            foreach ($sqls as $sql) {
                try {
                    $db->query($sql);
                } catch (Exception $e) {
                    // 继续执行
                }
            }
        } else {
            throw new Typecho_Plugin_Exception('不支持的数据库类型：' . $adapterName . '，仅支持 MySQL 5.7+/8.0+ 和 SQLite');
        }
    }

    /**
     * 评论提交钩子
     */
    public static function onCommentSubmit($comment, $edit)
    {
        // 获取插件配置
        $options = Helper::options();
        $pluginConfig = $options->plugin('CommentAI');
        
        // 检查插件是否启用
        if (!$pluginConfig->enablePlugin) {
            return;
        }

        // 记录原始数据用于调试
        self::log('钩子触发 - comment类型: ' . (is_array($comment) ? 'array' : 'object'));
        if (is_object($comment)) {
            self::log('comment对象属性: ' . json_encode(get_object_vars($comment), JSON_UNESCAPED_UNICODE));
        }

        // 获取评论信息
        $commentData = is_array($comment) ? $comment : array(
            'coid' => isset($comment->coid) ? $comment->coid : 0,
            'author' => isset($comment->author) ? $comment->author : '',
            'text' => isset($comment->text) ? $comment->text : '',
            'status' => isset($comment->status) ? $comment->status : 'approved',
            'type' => isset($comment->type) ? $comment->type : 'comment',
            'parent' => isset($comment->parent) ? $comment->parent : 0,
            'cid' => isset($comment->cid) ? $comment->cid : 0
        );
        
        self::log('处理后的commentData: ' . json_encode($commentData, JSON_UNESCAPED_UNICODE));

        // 检查是否是管理员评论（排除作者自己的评论）
        $adminUid = intval($pluginConfig->adminUid ?: 1);
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        
        // 获取评论的 authorId
        if ($commentData['coid']) {
            $commentRow = $db->fetchRow($db->select('authorId')
                ->from($prefix . 'comments')
                ->where('coid = ?', $commentData['coid'])
            );
            
            if ($commentRow && intval($commentRow['authorId']) == $adminUid) {
                self::log('跳过管理员自己的评论');
                return;
            }
        }
        
        // 应用触发条件过滤
        $triggerCondition = $pluginConfig->triggerCondition ? $pluginConfig->triggerCondition : array();
        
        // 检查是否需要跳过
        if (in_array('approved_only', $triggerCondition) && $commentData['status'] != 'approved') {
            return;
        }
        
        if (in_array('no_spam', $triggerCondition) && $commentData['status'] == 'spam') {
            return;
        }
        
        if (in_array('no_trackback', $triggerCondition) && ($commentData['type'] == 'trackback' || $commentData['type'] == 'pingback')) {
            return;
        }

        // 检查频率限制
        if (!self::checkRateLimit($pluginConfig)) {
            return;
        }

        // 异步处理AI回复（避免阻塞评论提交）
        try {
            require_once __DIR__ . '/ReplyManager.php';
            $manager = new CommentAI_ReplyManager($pluginConfig);
            $manager->processComment($commentData);
        } catch (Exception $e) {
            // 静默失败，不影响评论提交
            self::log('AI回复处理失败: ' . $e->getMessage(), 'ERROR');
        }
    }

    /**
     * 检查频率限制
     */
    private static function checkRateLimit($pluginConfig)
    {
        $rateLimit = intval($pluginConfig->rateLimit);
        if ($rateLimit <= 0) {
            return true; // 不限制
        }

        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $oneHourAgo = time() - 3600;
        
        try {
            $count = $db->fetchObject($db->select('COUNT(*) as count')
                ->from($prefix . 'comment_ai_queue')
                ->where('created_at > ?', $oneHourAgo)
            )->count;
            
            return $count < $rateLimit;
        } catch (Exception $e) {
            return true; // 出错时允许调用
        }
    }

    /**
     * 日志记录
     *
     * @param string $message 日志内容
     * @param string $level 日志级别：INFO / WARN / ERROR
     */
    public static function log($message, $level = 'INFO')
    {
        $logFile = __DIR__ . '/runtime.log';
        $maxSize = 2 * 1024 * 1024; // 2MB 轮转

        // 超过大小限制时轮转
        if (file_exists($logFile) && filesize($logFile) > $maxSize) {
            $backupFile = __DIR__ . '/runtime.log.1';
            @rename($logFile, $backupFile);
        }

        $time = date('Y-m-d H:i:s');
        $logMessage = "[{$time}] [{$level}] {$message}\n";
        @file_put_contents($logFile, $logMessage, FILE_APPEND);
    }

    /**
     * 读取日志内容
     *
     * @param int $lines 读取最后N行
     * @return string
     */
    public static function readLog($lines = 200)
    {
        $logFile = __DIR__ . '/runtime.log';
        if (!file_exists($logFile)) {
            return '';
        }

        $content = file($logFile, FILE_IGNORE_NEW_LINES);
        if ($content === false) {
            return '';
        }

        $content = array_slice($content, -$lines);
        return implode("\n", $content);
    }

    /**
     * 清空日志
     */
    public static function clearLog()
    {
        $logFile = __DIR__ . '/runtime.log';
        @file_put_contents($logFile, '');
        $backupFile = __DIR__ . '/runtime.log.1';
        if (file_exists($backupFile)) {
            @unlink($backupFile);
        }
    }
}
