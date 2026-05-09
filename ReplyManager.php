<?php
/**
 * 回复管理器 - 处理评论、生成回复、发布管理
 *
 * @package CommentAI
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class CommentAI_ReplyManager
{
    private $config;
    private $db;
    private $prefix;

    public function __construct($config)
    {
        $this->config = $config;
        $this->db = Typecho_Db::get();
        $this->prefix = $this->db->getPrefix();
    }

    /**
     * 处理评论并生成AI回复（主入口）
     */
    public function processComment($commentData, $skipDelay = false)
    {
        CommentAI_Plugin::log('收到评论数据: ' . json_encode($commentData, JSON_UNESCAPED_UNICODE));

        // 获取评论详细信息
        $comment = $this->getCommentDetails($commentData['coid']);
        if (!$comment) {
            CommentAI_Plugin::log('评论不存在，coid: ' . $commentData['coid'], 'ERROR');
            throw new Exception('评论不存在');
        }

        // 获取文章信息
        $post = $this->getPostDetails($comment->cid);
        if (!$post) {
            CommentAI_Plugin::log('文章不存在，cid: ' . $comment->cid, 'ERROR');
            throw new Exception('文章不存在');
        }

        // 检查是否启用批量合并模式
        $batchWindow = intval($this->config->batchWindow ?: 0);

        if (!$skipDelay && $batchWindow > 0) {
            // 即时合并处理模式
            $this->mergeAndProcessComment($comment, $post);
            return;
        }

        // 单条处理模式（含延迟）
        $replyDelay = intval($this->config->replyDelay ?: 0);
        if (!$skipDelay && $replyDelay > 0) {
            CommentAI_Plugin::log('将在 ' . $replyDelay . ' 秒后异步处理');
            $this->scheduleAsyncProcess($commentData, $replyDelay);
            return;
        }

        // 直接处理单条评论
        $this->processSingleComment($comment, $post);
    }

    /**
     * 处理单条评论（含评论链追溯）
     */
    private function processSingleComment($comment, $post)
    {
        // 低价值评论检测
        if ($this->isLowValueComment($comment->text)) {
            $mode = $this->config->lowValueMode ?: 'skip';

            if ($mode === 'skip') {
                // 跳过模式：使用固定回复
                $fixedReply = $this->config->lowValueReply ?: '感谢你的关注和支持！欢迎常来交流～';

                if ($this->config->showAIBadge) {
                    $badgeText = $this->config->aiBadgeText ?: '🤖 AI辅助回复';
                    $fixedReply .= "\n\n<small style=\"color:#999;\">{$badgeText}</small>";
                }

                switch ($this->config->replyMode) {
                    case 'auto':
                        $this->publishReply($comment, $fixedReply);
                        $this->saveToQueue($comment->coid, $comment->cid, $comment->author, $comment->text, $fixedReply, 'published');
                        break;
                    case 'audit':
                        $this->saveToQueue($comment->coid, $comment->cid, $comment->author, $comment->text, $fixedReply, 'pending');
                        break;
                    case 'suggest':
                        $this->saveToQueue($comment->coid, $comment->cid, $comment->author, $comment->text, $fixedReply, 'suggest');
                        break;
                }
                return;
            }

            // 精简模式：只发文章摘要，不发标题和评论链
            $simplifiedContext = array();
            $contextMode = is_array($this->config->contextMode) ? $this->config->contextMode : array();
            if (in_array('article_excerpt', $contextMode)) {
                $text = strip_tags($post->text);
                $simplifiedContext['article_excerpt'] = mb_substr($text, 0, 300, 'UTF-8');
            }

            require_once __DIR__ . '/AIService.php';
            $provider = CommentAI_AIService::create($this->config);

            try {
                $aiReply = $provider->generateReply($comment->text, $simplifiedContext);

                if (!$this->checkSensitiveWords($aiReply)) {
                    $this->saveToQueue($comment->coid, $comment->cid, $comment->author, $comment->text, $aiReply, 'rejected', '包含敏感词，已拦截');
                    return;
                }

                if ($this->config->showAIBadge) {
                    $badgeText = $this->config->aiBadgeText ?: '🤖 AI辅助回复';
                    $aiReply .= "\n\n<small style=\"color:#999;\">{$badgeText}</small>";
                }

                switch ($this->config->replyMode) {
                    case 'auto':
                        $this->publishReply($comment, $aiReply);
                        $this->saveToQueue($comment->coid, $comment->cid, $comment->author, $comment->text, $aiReply, 'published');
                        break;
                    case 'audit':
                        $this->saveToQueue($comment->coid, $comment->cid, $comment->author, $comment->text, $aiReply, 'pending');
                        break;
                    case 'suggest':
                        $this->saveToQueue($comment->coid, $comment->cid, $comment->author, $comment->text, $aiReply, 'suggest');
                        break;
                }
            } catch (Exception $e) {
                $this->saveToQueue($comment->coid, $comment->cid, $comment->author, $comment->text, '', 'error', $e->getMessage());
            }
            return;
        }

        // 构建上下文（含评论链）
        $context = $this->buildContext($comment, $post);

        // 调用 AI 服务
        require_once __DIR__ . '/AIService.php';
        $provider = CommentAI_AIService::create($this->config);

        try {
            $aiReply = $provider->generateReply($comment->text, $context);

            // 敏感词过滤
            if (!$this->checkSensitiveWords($aiReply)) {
                $this->saveToQueue(
                    $comment->coid,
                    $comment->cid,
                    $comment->author,
                    $comment->text,
                    $aiReply,
                    'rejected',
                    '包含敏感词，已拦截'
                );
                return;
            }

            // 添加 AI 标识
            if ($this->config->showAIBadge) {
                $badgeText = $this->config->aiBadgeText ?: '🤖 AI辅助回复';
                $aiReply .= "\n\n<small style=\"color:#999;\">{$badgeText}</small>";
            }

            // 根据回复模式处理
            switch ($this->config->replyMode) {
                case 'auto':
                    $this->publishReply($comment, $aiReply);
                    $this->saveToQueue($comment->coid, $comment->cid, $comment->author, $comment->text, $aiReply, 'published');
                    break;
                case 'audit':
                    $this->saveToQueue($comment->coid, $comment->cid, $comment->author, $comment->text, $aiReply, 'pending');
                    break;
                case 'suggest':
                    $this->saveToQueue($comment->coid, $comment->cid, $comment->author, $comment->text, $aiReply, 'suggest');
                    break;
            }

        } catch (Exception $e) {
            $this->saveToQueue($comment->coid, $comment->cid, $comment->author, $comment->text, '', 'error', $e->getMessage());
            throw $e;
        }
    }

    // ==================== 评论链追溯 ====================

    /**
     * 构建上下文信息（含评论链追溯）
     */
    private function buildContext($comment, $post)
    {
        $context = array();
        $contextMode = is_array($this->config->contextMode) ? $this->config->contextMode : array();

        // 文章标题
        if (in_array('article_title', $contextMode)) {
            $context['article_title'] = $post->title;
        }

        // 文章摘要
        if (in_array('article_excerpt', $contextMode)) {
            $text = strip_tags($post->text);
            $context['article_excerpt'] = mb_substr($text, 0, 300, 'UTF-8');
        }

        // 完整评论链追溯（替代原来的单层 parent_comment）
        if (in_array('parent_comment', $contextMode) && $comment->parent > 0) {
            $context['comment_chain'] = $this->buildCommentChain($comment);
        }

        return $context;
    }

    /**
     * 构建完整评论链（向上追溯最多10层）
     *
     * @param object $comment 当前评论
     * @return array 评论链，按时间顺序排列
     */
    private function buildCommentChain($comment)
    {
        $chain = array();
        $current = $comment;
        $maxDepth = 10;

        while ($current->parent > 0 && $maxDepth-- > 0) {
            $parent = $this->getCommentDetails($current->parent);
            if (!$parent) break;

            // 只追溯已审核通过的评论
            if ($parent->status === 'approved') {
                array_unshift($chain, array(
                    'author' => $parent->author,
                    'text' => $parent->text,
                    'is_ai' => $this->isAIReply($parent)
                ));
            }

            $current = $parent;
        }

        return $chain;
    }

    /**
     * 判断评论是否是 AI 回复
     */
    private function isAIReply($comment)
    {
        return isset($comment->agent) && strpos($comment->agent, 'CommentAI') !== false;
    }

    // ==================== 批量处理 ====================

    /**
     * 生成游客唯一标识
     */
    private function getVisitorKey($comment)
    {
        $author = isset($comment->author) ? $comment->author : '';
        $mail = isset($comment->mail) ? $comment->mail : '';
        return md5($author . '|' . $mail);
    }

    /**
     * 即时合并处理：有批次则合并处理，无批次则立即处理+创建批次文件
     */
    private function mergeAndProcessComment($comment, $post)
    {
        // 低价值评论不进入批量队列，直接处理
        if ($this->isLowValueComment($comment->text)) {
            $this->processSingleComment($comment, $post);
            return;
        }

        $visitorKey = $this->getVisitorKey($comment);
        $batchFile = __DIR__ . '/batch_' . $visitorKey . '_' . $comment->cid . '.json';

        if (!file_exists($batchFile)) {
            // 无批次文件：立即处理 + 创建文件供后续评论合并
            $this->triggerBackgroundProcess();
            $this->processSingleComment($comment, $post);
            $this->collectComment($comment, $post);
            return;
        }

        // 有批次文件：追加当前评论 → 删除文件 → 立即合并处理
        $this->collectComment($comment, $post);

        // 读取并原子占有批次文件
        $batchData = json_decode(file_get_contents($batchFile), true);
        @unlink($batchFile);

        if (!$batchData || empty($batchData['comments'])) {
            return;
        }

        CommentAI_Plugin::log('即时合并处理: 游客=' . $comment->author . ', 文章=' . $comment->cid . ', 评论数=' . count($batchData['comments']));

        $this->processBatchData($batchData);
    }

    /**
     * 收集评论到批量队列（按游客+文章分组）
     *
     * @param object $comment 评论详情
     * @param object $post 文章详情
     */
    private function collectComment($comment, $post)
    {
        $visitorKey = $this->getVisitorKey($comment);
        $batchFile = __DIR__ . '/batch_' . $visitorKey . '_' . $comment->cid . '.json';
        $now = time();

        if (file_exists($batchFile)) {
            $batchData = json_decode(file_get_contents($batchFile), true);
            if (!$batchData || !isset($batchData['comments'])) {
                $batchData = null;
            }
        }

        if (empty($batchData)) {
            $batchData = array(
                'visitorKey' => $visitorKey,
                'visitorAuthor' => $comment->author,
                'postId' => intval($comment->cid),
                'postTitle' => $post->title,
                'postExcerpt' => mb_substr(strip_tags($post->text), 0, 300, 'UTF-8'),
                'comments' => array(),
                'collectTime' => $now
            );
        }

        // 追加评论
        $chain = ($comment->parent > 0) ? $this->buildCommentChain($comment) : array();

        $batchData['comments'][] = array(
            'coid' => intval($comment->coid),
            'author' => $comment->author,
            'text' => $comment->text,
            'parent' => intval($comment->parent),
            'chain' => $chain
        );

        file_put_contents($batchFile, json_encode($batchData, JSON_UNESCAPED_UNICODE));

        CommentAI_Plugin::log('已收集评论到批量队列: 游客=' . $comment->author . ', 文章=' . $comment->cid . ', coid=' . $comment->coid . ', 当前批次共 ' . count($batchData['comments']) . ' 条');
    }

    /**
     * 处理批量评论（同一游客+同一篇文章的多条评论）
     *
     * @param string $batchFile 批量文件路径
     */
    private function processBatch($batchFile)
    {
        $batchData = json_decode(file_get_contents($batchFile), true);
        if (!$batchData || empty($batchData['comments'])) {
            @unlink($batchFile);
            return;
        }

        @unlink($batchFile);
        $this->processBatchData($batchData);
    }

    /**
     * 处理批量评论数据（核心逻辑）
     *
     * @param array $batchData 批量评论数据
     */
    private function processBatchData($batchData)
    {
        $comments = $batchData['comments'];
        $postId = $batchData['postId'];
        $postTitle = $batchData['postTitle'];
        $postExcerpt = $batchData['postExcerpt'];
        $visitorAuthor = $batchData['visitorAuthor'];

        // 过滤已在队列中的评论（避免重复处理）
        $pendingComments = array();
        foreach ($comments as $c) {
            if (!$this->isInQueue($c['coid'])) {
                $pendingComments[] = $c;
            }
        }

        if (empty($pendingComments)) {
            CommentAI_Plugin::log('批量评论全部已在队列中，跳过: 游客=' . $visitorAuthor . ', 文章=' . $postId);
            return;
        }

        CommentAI_Plugin::log('处理批量评论: 游客=' . $visitorAuthor . ', 文章=' . $postId . ', 待处理=' . count($pendingComments) . '/' . count($comments));

        // 只有1条评论时，退化为单条处理
        if (count($pendingComments) === 1) {
            $comment = $this->getCommentDetails($pendingComments[0]['coid']);
            $post = $this->getPostDetails($postId);
            if ($comment && $post) {
                $this->processSingleComment($comment, $post);
            }
            return;
        }

        // 批量调用 AI
        require_once __DIR__ . '/AIService.php';
        $provider = CommentAI_AIService::create($this->config);

        try {
            $results = $provider->generateBatchReplies($postTitle, $postExcerpt, $pendingComments);

            // 逐条保存结果
            $coidMap = array();
            foreach ($pendingComments as $c) {
                $coidMap[$c['coid']] = $c;
            }

            foreach ($results as $result) {
                $coid = $result['coid'];
                $reply = $result['reply'];

                if (empty($reply) || !isset($coidMap[$coid])) {
                    continue;
                }

                $commentInfo = $coidMap[$coid];
                $comment = $this->getCommentDetails($coid);
                if (!$comment) continue;

                // 敏感词过滤
                if (!$this->checkSensitiveWords($reply)) {
                    $this->saveToQueue($coid, $postId, $commentInfo['author'], $commentInfo['text'], $reply, 'rejected', '包含敏感词，已拦截');
                    continue;
                }

                // 添加 AI 标识
                if ($this->config->showAIBadge) {
                    $badgeText = $this->config->aiBadgeText ?: '🤖 AI辅助回复';
                    $reply .= "\n\n<small style=\"color:#999;\">{$badgeText}</small>";
                }

                // 根据回复模式处理
                switch ($this->config->replyMode) {
                    case 'auto':
                        $this->publishReply($comment, $reply);
                        $this->saveToQueue($coid, $postId, $commentInfo['author'], $commentInfo['text'], $reply, 'published');
                        break;
                    case 'audit':
                        $this->saveToQueue($coid, $postId, $commentInfo['author'], $commentInfo['text'], $reply, 'pending');
                        break;
                    case 'suggest':
                        $this->saveToQueue($coid, $postId, $commentInfo['author'], $commentInfo['text'], $reply, 'suggest');
                        break;
                }
            }

        } catch (Exception $e) {
            CommentAI_Plugin::log('批量处理失败，降级为逐条处理: ' . $e->getMessage(), 'ERROR');

            // Fallback：逐条处理
            foreach ($pendingComments as $c) {
                try {
                    $comment = $this->getCommentDetails($c['coid']);
                    $post = $this->getPostDetails($postId);
                    if ($comment && $post) {
                        $this->processSingleComment($comment, $post);
                    }
                } catch (Exception $ex) {
                    $this->saveToQueue($c['coid'], $postId, $c['author'], $c['text'], '', 'error', $ex->getMessage());
                }
            }
        }
    }

    /**
     * 检查评论是否已在队列中
     */
    private function isInQueue($coid)
    {
        $existing = $this->db->fetchRow($this->db->select()
            ->from($this->prefix . 'comment_ai_queue')
            ->where('cid = ?', $coid)
        );
        return !empty($existing);
    }

    // ==================== 数据库操作 ====================

    /**
     * 获取评论详情
     */
    private function getCommentDetails($coid)
    {
        $row = $this->db->fetchRow($this->db->select()
            ->from($this->prefix . 'comments')
            ->where('coid = ?', $coid)
        );
        return $row ? (object)$row : null;
    }

    /**
     * 获取文章详情
     */
    private function getPostDetails($cid)
    {
        $row = $this->db->fetchRow($this->db->select()
            ->from($this->prefix . 'contents')
            ->where('cid = ?', $cid)
        );
        return $row ? (object)$row : null;
    }

    /**
     * 敏感词检查
     */
    private function checkSensitiveWords($text)
    {
        $sensitiveWords = $this->config->sensitiveWords;
        if (empty($sensitiveWords)) {
            return true;
        }

        $words = array_filter(array_map('trim', explode("\n", $sensitiveWords)));
        foreach ($words as $word) {
            if (empty($word)) continue;
            if (mb_strpos($text, $word, 0, 'UTF-8') !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * 低价值评论检测
     *
     * @param string $text 评论内容
     * @return bool 是否为低价值评论
     */
    private function isLowValueComment($text)
    {
        if (!$this->config->lowValueDetection) {
            return false;
        }

        $trimmed = trim($text);

        // 关键词完全匹配
        $lowValueWords = $this->config->lowValueWords;
        if (!empty($lowValueWords)) {
            $words = array_filter(array_map('trim', explode("\n", $lowValueWords)));
            foreach ($words as $word) {
                if (empty($word)) continue;
                if ($trimmed === $word || mb_strtolower($trimmed, 'UTF-8') === mb_strtolower($word, 'UTF-8')) {
                    return true;
                }
            }
        }

        // 纯数字且长度 <= 4（如 "1"、"666"、"1111"）
        if (preg_match('/^\d{1,4}$/', $trimmed)) {
            return true;
        }

        return false;
    }

    /**
     * 发布回复
     */
    private function publishReply($comment, $replyText)
    {
        $adminUid = intval($this->config->adminUid ?: 1);

        $admin = $this->db->fetchRow($this->db->select()
            ->from($this->prefix . 'users')
            ->where('uid = ?', $adminUid)
        );

        if (!$admin) {
            throw new Exception('管理员用户不存在');
        }

        $admin = (object)$admin;

        $data = array(
            'cid' => $comment->cid,
            'created' => time(),
            'author' => $admin->name,
            'authorId' => $admin->uid,
            'ownerId' => $admin->uid,
            'mail' => $admin->mail,
            'url' => Helper::options()->siteUrl,
            'ip' => '127.0.0.1',
            'agent' => 'CommentAI Plugin',
            'text' => $replyText,
            'type' => 'comment',
            'status' => 'approved',
            'parent' => $comment->coid
        );

        $insertId = $this->db->query($this->db->insert($this->prefix . 'comments')->rows($data));

        // 更新文章评论数
        $post = $this->db->fetchRow($this->db->select('commentsNum')
            ->from($this->prefix . 'contents')
            ->where('cid = ?', $comment->cid)
        );

        if ($post) {
            $newCount = intval($post['commentsNum']) + 1;
            $this->db->query($this->db->update($this->prefix . 'contents')
                ->rows(array('commentsNum' => $newCount))
                ->where('cid = ?', $comment->cid)
            );
        }

        return $insertId;
    }

    /**
     * 保存到队列
     */
    private function saveToQueue($coid, $postId, $author, $commentText, $aiReply, $status = 'pending', $errorMsg = null, $processedAt = 0)
    {
        $data = array(
            'cid' => $coid,
            'post_id' => $postId,
            'comment_author' => $author,
            'comment_text' => $commentText,
            'ai_reply' => $aiReply,
            'status' => $status,
            'created_at' => time(),
            'processed_at' => $processedAt,
            'error_msg' => $errorMsg
        );

        $existing = $this->db->fetchRow($this->db->select()
            ->from($this->prefix . 'comment_ai_queue')
            ->where('cid = ?', $coid)
        );

        if ($existing) {
            $this->db->query($this->db->update($this->prefix . 'comment_ai_queue')
                ->rows($data)
                ->where('cid = ?', $coid)
            );
        } else {
            $this->db->query($this->db->insert($this->prefix . 'comment_ai_queue')->rows($data));
        }
    }

    // ==================== 异步调度 ====================

    /**
     * 异步调度处理（延迟回复）
     */
    private function scheduleAsyncProcess($commentData, $delay)
    {
        $scheduleFile = __DIR__ . '/schedule_' . $commentData['coid'] . '.json';
        $scheduleData = array(
            'commentData' => $commentData,
            'processTime' => time() + $delay,
            'created' => time()
        );

        file_put_contents($scheduleFile, json_encode($scheduleData));
        $this->triggerBackgroundProcess();
    }

    /**
     * 触发后台处理
     */
    private function triggerBackgroundProcess()
    {
        $url = Helper::options()->siteUrl . 'action/comment-ai?do=process_scheduled';
        $urlParts = parse_url($url);

        $fp = @fsockopen($urlParts['host'], isset($urlParts['port']) ? $urlParts['port'] : 80, $errno, $errstr, 1);
        if ($fp) {
            $out = "GET " . $urlParts['path'] . "?" . $urlParts['query'] . " HTTP/1.1\r\n";
            $out .= "Host: " . $urlParts['host'] . "\r\n";
            $out .= "Connection: Close\r\n\r\n";
            fwrite($fp, $out);
            fclose($fp);
        }
    }

    /**
     * 处理计划任务（延迟 + 批量）
     */
    public function processScheduledTasks()
    {
        $now = time();

        // 1. 处理延迟任务
        $scheduleFiles = glob(__DIR__ . '/schedule_*.json');
        foreach ($scheduleFiles as $file) {
            $data = json_decode(file_get_contents($file), true);

            if (!$data || !isset($data['processTime'])) {
                @unlink($file);
                continue;
            }

            if ($data['processTime'] <= $now) {
                try {
                    $this->processComment($data['commentData'], true);
                    CommentAI_Plugin::log('已处理延迟任务: ' . $data['commentData']['coid']);
                } catch (Exception $e) {
                    CommentAI_Plugin::log('处理延迟任务失败: ' . $e->getMessage(), 'ERROR');
                }
                @unlink($file);
            }
        }

        // 2. 处理批量任务（3秒兜底窗口，处理孤立的批次文件）
        $batchFiles = glob(__DIR__ . '/batch_*.json');
        foreach ($batchFiles as $file) {
            $data = json_decode(file_get_contents($file), true);

            if (!$data || !isset($data['collectTime'])) {
                @unlink($file);
                continue;
            }

            // 3秒兜底窗口到期，处理这批评论
            if ($data['collectTime'] + 3 <= $now) {
                try {
                    $this->processBatch($file);
                    CommentAI_Plugin::log('已处理兜底批量任务: 游客=' . $data['visitorAuthor'] . ', 文章=' . $data['postId'] . ', 评论数=' . count($data['comments']));
                } catch (Exception $e) {
                    CommentAI_Plugin::log('处理兜底批量任务失败: ' . $e->getMessage(), 'ERROR');
                }
            }
        }
    }

    // ==================== 队列管理 ====================

    /**
     * 从队列中发布回复
     */
    public function publishFromQueue($queueId)
    {
        $queue = $this->db->fetchRow($this->db->select()
            ->from($this->prefix . 'comment_ai_queue')
            ->where('id = ?', $queueId)
        );

        if (!$queue) {
            throw new Exception('队列记录不存在');
        }

        $queue = (object)$queue;

        $comment = $this->getCommentDetails($queue->cid);
        if (!$comment) {
            throw new Exception('原评论不存在');
        }

        $this->publishReply($comment, $queue->ai_reply);

        $this->db->query($this->db->update($this->prefix . 'comment_ai_queue')
            ->rows(array(
                'status' => 'published',
                'processed_at' => time()
            ))
            ->where('id = ?', $queueId)
        );

        return true;
    }

    /**
     * 拒绝队列中的回复
     */
    public function rejectFromQueue($queueId, $reason = '')
    {
        $this->db->query($this->db->update($this->prefix . 'comment_ai_queue')
            ->rows(array(
                'status' => 'rejected',
                'processed_at' => time(),
                'error_msg' => $reason
            ))
            ->where('id = ?', $queueId)
        );

        return true;
    }

    /**
     * 获取队列列表
     */
    public function getQueueList($status = null, $page = 1, $pageSize = 20)
    {
        $select = $this->db->select()->from($this->prefix . 'comment_ai_queue');

        if ($status) {
            $select->where('status = ?', $status);
        }

        $select->order('created_at', Typecho_Db::SORT_DESC)
               ->page($page, $pageSize);

        $rows = $this->db->fetchAll($select);

        return array_map(function($row) {
            return (object)$row;
        }, $rows);
    }

    /**
     * 获取队列统计
     */
    public function getQueueStats()
    {
        $stats = array(
            'pending' => 0,
            'published' => 0,
            'rejected' => 0,
            'suggest' => 0,
            'error' => 0,
            'total' => 0
        );

        $rows = $this->db->fetchAll($this->db->select('status, COUNT(*) as count')
            ->from($this->prefix . 'comment_ai_queue')
            ->group('status')
        );

        foreach ($rows as $row) {
            $row = (object)$row;
            $stats[$row->status] = intval($row->count);
            $stats['total'] += intval($row->count);
        }

        return $stats;
    }

    /**
     * 批量处理队列
     */
    public function batchProcess($ids, $action)
    {
        $success = 0;
        $failed = 0;

        foreach ($ids as $id) {
            try {
                if ($action == 'publish') {
                    $this->publishFromQueue($id);
                    $success++;
                } elseif ($action == 'reject') {
                    $this->rejectFromQueue($id, '批量拒绝');
                    $success++;
                }
            } catch (Exception $e) {
                $failed++;
            }
        }

        return array('success' => $success, 'failed' => $failed);
    }

    /**
     * 清理旧队列记录
     */
    public function cleanOldQueue($days = 30)
    {
        $timestamp = time() - ($days * 86400);

        $this->db->query($this->db->delete($this->prefix . 'comment_ai_queue')
            ->where('created_at < ?', $timestamp)
            ->where('status IN ?', array('published', 'rejected'))
        );
    }
}
