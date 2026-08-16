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
     * 写入计划任务并触发后台处理（不阻塞评论提交）
     */
    public function scheduleComment($commentData)
    {
        if (empty($commentData['coid'])) {
            throw new Exception('评论ID为空');
        }

        if ($this->isInQueue($commentData['coid'])) {
            CommentAI_Plugin::log('评论已在队列中，跳过: coid=' . $commentData['coid']);
            return;
        }

        $scheduleFile = __DIR__ . '/schedule_' . intval($commentData['coid']) . '.json';
        $scheduleData = array(
            'commentData' => $commentData,
            'processTime' => time(),
            'created' => time()
        );

        file_put_contents($scheduleFile, json_encode($scheduleData, JSON_UNESCAPED_UNICODE));
        CommentAI_Plugin::log('已写入计划任务: coid=' . $commentData['coid']);
        $this->triggerBackgroundProcess();
    }

    /**
     * 处理评论并生成AI回复（同步入口，供计划任务 / 重新生成使用）
     *
     * @param array $commentData
     * @param bool $force 强制重新生成（覆盖队列）
     */
    public function processComment($commentData, $force = false)
    {
        CommentAI_Plugin::log('开始处理评论: ' . json_encode($commentData, JSON_UNESCAPED_UNICODE));

        if (!$force && $this->isInQueue($commentData['coid'])) {
            CommentAI_Plugin::log('评论已在队列中，跳过: coid=' . $commentData['coid']);
            return;
        }

        $comment = $this->getCommentDetails($commentData['coid']);
        if (!$comment) {
            CommentAI_Plugin::log('评论不存在，coid: ' . $commentData['coid'], 'ERROR');
            throw new Exception('评论不存在');
        }

        $post = $this->getPostDetails($comment->cid);
        if (!$post) {
            CommentAI_Plugin::log('文章不存在，cid: ' . $comment->cid, 'ERROR');
            throw new Exception('文章不存在');
        }

        $this->processSingleComment($comment, $post);
    }

    /**
     * 处理单条评论
     */
    private function processSingleComment($comment, $post)
    {
        if ($this->isLowValueComment($comment->text)) {
            $fixedReply = $this->config->lowValueReply ?: '感谢你的关注和支持！欢迎常来交流～';
            $this->finalizeReply($comment, $fixedReply);
            return;
        }

        $context = $this->buildContext($comment, $post);

        require_once __DIR__ . '/AIService.php';
        $provider = CommentAI_AIService::create($this->config);

        try {
            $aiReply = $provider->generateReply($comment->text, $context);
            $aiReply = is_string($aiReply) ? trim($aiReply) : '';

            if ($aiReply === '') {
                throw new Exception('AI返回空内容。若使用思考/推理模型，请关闭思考模式或增大「最大Token数」');
            }

            CommentAI_Plugin::log('AI回复已生成: coid=' . $comment->coid . ', 长度=' . mb_strlen($aiReply, 'UTF-8'));

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

            $this->finalizeReply($comment, $aiReply);
        } catch (Exception $e) {
            $this->saveToQueue($comment->coid, $comment->cid, $comment->author, $comment->text, '', 'error', $e->getMessage());
            throw $e;
        }
    }

    /**
     * 追加标识并按回复模式入库 / 发布
     */
    private function finalizeReply($comment, $replyText)
    {
        $replyText = is_string($replyText) ? trim($replyText) : '';
        if ($replyText === '') {
            throw new Exception('AI返回空内容，已取消发布');
        }

        if ($this->config->showAIBadge) {
            $badgeText = $this->config->aiBadgeText ?: '🤖 AI辅助回复';
            $replyText .= "\n\n<small style=\"color:#999;\">{$badgeText}</small>";
        }

        $existingAI = $this->findExistingAIReply($comment->coid);
        if ($existingAI) {
            $this->updateReply($existingAI->coid, $replyText);
            $this->saveToQueue($comment->coid, $comment->cid, $comment->author, $comment->text, $replyText, 'published');
            CommentAI_Plugin::log('已覆盖已发布的AI回复: reply_coid=' . $existingAI->coid);
            return;
        }

        switch ($this->config->replyMode) {
            case 'auto':
                $this->publishReply($comment, $replyText);
                $this->saveToQueue($comment->coid, $comment->cid, $comment->author, $comment->text, $replyText, 'published');
                break;
            default:
                $this->saveToQueue($comment->coid, $comment->cid, $comment->author, $comment->text, $replyText, 'pending');
                break;
        }
    }

    /**
     * 查找该评论下已发布的 AI 回复（按 coid 倒序取最新一条）
     */
    private function findExistingAIReply($parentCoid)
    {
        $row = $this->db->fetchRow($this->db->select()
            ->from($this->prefix . 'comments')
            ->where('parent = ?', intval($parentCoid))
            ->where('agent LIKE ?', '%CommentAI%')
            ->order('coid', Typecho_Db::SORT_DESC)
        );
        return $row ? (object)$row : null;
    }

    /**
     * 覆盖已发布的 AI 回复正文，不新增评论、不改 commentsNum
     */
    private function updateReply($coid, $replyText)
    {
        $this->db->query($this->db->update($this->prefix . 'comments')
            ->rows(array(
                'text' => $replyText,
                'agent' => 'CommentAI Plugin'
            ))
            ->where('coid = ?', intval($coid))
        );
    }

    /**
     * 构建上下文信息（含评论链追溯）
     */
    private function buildContext($comment, $post)
    {
        $context = array();
        $contextMode = is_array($this->config->contextMode) ? $this->config->contextMode : array();

        if (in_array('article_title', $contextMode)) {
            $context['article_title'] = $post->title;
        }

        if (in_array('article_excerpt', $contextMode)) {
            $text = strip_tags($post->text);
            $context['article_excerpt'] = mb_substr($text, 0, 300, 'UTF-8');
        }

        if (in_array('parent_comment', $contextMode) && $comment->parent > 0) {
            $context['comment_chain'] = $this->buildCommentChain($comment);
        }

        return $context;
    }

    /**
     * 构建完整评论链（向上追溯最多10层）
     *
     * @param object $comment 当前评论
     * @return array
     */
    private function buildCommentChain($comment)
    {
        $chain = array();
        $current = $comment;
        $maxDepth = 10;

        while ($current->parent > 0 && $maxDepth-- > 0) {
            $parent = $this->getCommentDetails($current->parent);
            if (!$parent) {
                break;
            }

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

    /**
     * 检查评论是否已在队列中
     */
    public function isInQueue($coid)
    {
        $existing = $this->db->fetchRow($this->db->select()
            ->from($this->prefix . 'comment_ai_queue')
            ->where('cid = ?', $coid)
        );
        return !empty($existing);
    }

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
            if ($word === '') {
                continue;
            }
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
     * @return bool
     */
    private function isLowValueComment($text)
    {
        if (!$this->config->lowValueDetection) {
            return false;
        }

        $trimmed = trim($text);

        $lowValueWords = $this->config->lowValueWords;
        if (!empty($lowValueWords)) {
            $words = array_filter(array_map('trim', explode("\n", $lowValueWords)));
            foreach ($words as $word) {
                if ($word === '') {
                    continue;
                }
                if ($trimmed === $word || mb_strtolower($trimmed, 'UTF-8') === mb_strtolower($word, 'UTF-8')) {
                    return true;
                }
            }
        }

        if (preg_match('/^\d{1,4}$/', $trimmed)) {
            return true;
        }

        return false;
    }

    /**
     * 发布回复（字段对齐 Typecho Widget_Feedback / Comments_Edit）
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
        $post = $this->getPostDetails($comment->cid);
        $ownerId = $post && !empty($post->authorId) ? intval($post->authorId) : intval($admin->uid);
        $created = Helper::options()->time ? intval(Helper::options()->time) : time();

        $data = array(
            'cid' => $comment->cid,
            'created' => $created,
            'author' => $admin->screenName ? $admin->screenName : $admin->name,
            'authorId' => $admin->uid,
            'ownerId' => $ownerId,
            'mail' => $admin->mail,
            'url' => isset($admin->url) && $admin->url ? $admin->url : Helper::options()->siteUrl,
            'ip' => '127.0.0.1',
            'agent' => 'CommentAI Plugin',
            'text' => $replyText,
            'type' => 'comment',
            'status' => 'approved',
            'parent' => $comment->coid
        );

        $insertId = $this->db->query($this->db->insert($this->prefix . 'comments')->rows($data));

        $this->db->query($this->db->update($this->prefix . 'contents')
            ->expression('commentsNum', 'commentsNum + 1')
            ->where('cid = ?', $comment->cid)
        );

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

    /**
     * 触发后台处理
     * Typecho 1.3.0 使用官方 Helper::requestService；1.2.1 使用支持 HTTPS 的短超时 curl
     */
    private function triggerBackgroundProcess()
    {
        if (CommentAI_Plugin::isTypecho13() && method_exists('Helper', 'requestService')) {
            try {
                Helper::requestService('commentAiProcess');
                CommentAI_Plugin::log('已通过 Widget_Service 触发异步处理');
                return;
            } catch (Exception $e) {
                CommentAI_Plugin::log('requestService 失败，回退 HTTP 触发: ' . $e->getMessage(), 'WARN');
            }
        }

        $index = rtrim(Helper::options()->index, '/');
        $url = $index . '/action/comment-ai?do=process_scheduled&token=' . urlencode(CommentAI_Plugin::asyncToken());

        if (!function_exists('curl_init')) {
            CommentAI_Plugin::log('未启用 curl，无法触发后台处理', 'ERROR');
            return;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 800);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 500);
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_exec($ch);
        curl_close($ch);
        CommentAI_Plugin::log('已通过 HTTP 触发后台处理');
    }

    /**
     * 处理计划任务
     */
    public function processScheduledTasks()
    {
        $now = time();
        $scheduleFiles = glob(__DIR__ . '/schedule_*.json');

        foreach ($scheduleFiles as $file) {
            $data = json_decode(file_get_contents($file), true);

            if (!$data || empty($data['commentData']) || !isset($data['processTime'])) {
                @unlink($file);
                continue;
            }

            if ($data['processTime'] > $now) {
                continue;
            }

            @unlink($file);

            try {
                $this->processComment($data['commentData']);
                CommentAI_Plugin::log('已处理计划任务: coid=' . $data['commentData']['coid']);
            } catch (Exception $e) {
                CommentAI_Plugin::log('处理计划任务失败: ' . $e->getMessage(), 'ERROR');
            }
        }

        $batchFiles = glob(__DIR__ . '/batch_*.json');
        foreach ($batchFiles as $file) {
            @unlink($file);
        }
    }

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

        return array_map(function ($row) {
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
            'error' => 0,
            'total' => 0
        );

        $rows = $this->db->fetchAll($this->db->select('status, COUNT(*) as count')
            ->from($this->prefix . 'comment_ai_queue')
            ->group('status')
        );

        foreach ($rows as $row) {
            $row = (object)$row;
            if (!isset($stats[$row->status])) {
                $stats[$row->status] = 0;
            }
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
