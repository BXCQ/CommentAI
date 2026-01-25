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
     * 处理评论并生成AI回复
     */
    public function processComment($commentData, $skipDelay = false)
    {
        // 调试日志
        CommentAI_Plugin::log('收到评论数据: ' . json_encode($commentData, JSON_UNESCAPED_UNICODE));
        
        // 检查是否需要延迟回复（仅在非跳过模式下）
        $replyDelay = intval($this->config->replyDelay ?: 0);
        if (!$skipDelay && $replyDelay > 0) {
            // 使用后台异步处理
            CommentAI_Plugin::log('将在 ' . $replyDelay . ' 秒后异步处理');
            $this->scheduleAsyncProcess($commentData, $replyDelay);
            return;
        }
        
        // 获取评论详细信息
        $comment = $this->getCommentDetails($commentData['coid']);
        if (!$comment) {
            CommentAI_Plugin::log('评论不存在，coid: ' . $commentData['coid']);
            throw new Exception('评论不存在');
        }
        
        CommentAI_Plugin::log('评论信息: coid=' . $comment->coid . ', cid=' . $comment->cid);

        // 获取文章信息
        $post = $this->getPostDetails($comment->cid);
        if (!$post) {
            CommentAI_Plugin::log('文章不存在，cid: ' . $comment->cid);
            throw new Exception('文章不存在');
        }
        
        CommentAI_Plugin::log('文章信息: cid=' . $post->cid . ', title=' . $post->title);

        // 构建上下文
        $context = $this->buildContext($comment, $post);

        // 调用AI服务生成回复
        require_once __DIR__ . '/AIService.php';
        $aiService = new CommentAI_AIService($this->config);
        
        try {
            $aiReply = $aiService->generateReply($comment->text, $context);
            
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

            // 添加AI标识
            if ($this->config->showAIBadge) {
                $badgeText = $this->config->aiBadgeText ?: '🤖 AI辅助回复';
                $aiReply .= "\n\n<small style=\"color:#999;\">{$badgeText}</small>";
            }

            // 根据回复模式处理
            switch ($this->config->replyMode) {
                case 'auto':
                    // 全自动模式：直接发布
                    $this->publishReply($comment, $aiReply);
                    $this->saveToQueue(
                        $comment->coid,
                        $comment->cid,
                        $comment->author,
                        $comment->text,
                        $aiReply,
                        'published'
                    );
                    break;

                case 'audit':
                    // 人工审核模式：保存到队列
                    $this->saveToQueue(
                        $comment->coid,
                        $comment->cid,
                        $comment->author,
                        $comment->text,
                        $aiReply,
                        'pending'
                    );
                    break;

                case 'suggest':
                    // 仅建议模式：保存到队列但标记为建议
                    $this->saveToQueue(
                        $comment->coid,
                        $comment->cid,
                        $comment->author,
                        $comment->text,
                        $aiReply,
                        'suggest'
                    );
                    break;
            }

        } catch (Exception $e) {
            // 保存错误信息
            $this->saveToQueue(
                $comment->coid,
                $comment->cid,
                $comment->author,
                $comment->text,
                '',
                'error',
                $e->getMessage()
            );
            throw $e;
        }
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
        
        // 转换为对象（如果是数组）
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
        
        // 转换为对象（如果是数组）
        return $row ? (object)$row : null;
    }

    /**
     * 构建上下文信息
     */
    private function buildContext($comment, $post)
    {
        $context = array();
        $contextMode = $this->config->contextMode ? $this->config->contextMode : array();

        // 文章标题
        if (in_array('article_title', $contextMode)) {
            $context['article_title'] = $post->title;
        }

        // 文章摘要
        if (in_array('article_excerpt', $contextMode)) {
            // 移除HTML标签
            $text = strip_tags($post->text);
            $context['article_excerpt'] = mb_substr($text, 0, 300, 'UTF-8');
        }

        // 父评论
        if (in_array('parent_comment', $contextMode) && $comment->parent > 0) {
            $parentComment = $this->getCommentDetails($comment->parent);
            if ($parentComment) {
                $context['parent_comment'] = $parentComment->author . ': ' . $parentComment->text;
            }
        }

        return $context;
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
     * 发布回复
     */
    private function publishReply($comment, $replyText)
    {
        $adminUid = intval($this->config->adminUid ?: 1);
        
        // 获取管理员信息
        $admin = $this->db->fetchRow($this->db->select()
            ->from($this->prefix . 'users')
            ->where('uid = ?', $adminUid)
        );

        if (!$admin) {
            throw new Exception('管理员用户不存在');
        }
        
        $admin = (object)$admin; // 转换为对象

        // 插入回复
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
        
        // 更新文章评论数 - 使用兼容的方式
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

        // 检查是否已存在
        $existing = $this->db->fetchRow($this->db->select()
            ->from($this->prefix . 'comment_ai_queue')
            ->where('cid = ?', $coid)
        );

        if ($existing) {
            // 更新
            $this->db->query($this->db->update($this->prefix . 'comment_ai_queue')
                ->rows($data)
                ->where('cid = ?', $coid)
            );
        } else {
            // 插入
            $this->db->query($this->db->insert($this->prefix . 'comment_ai_queue')->rows($data));
        }
    }
    
    /**
     * 异步调度处理（使用文件锁机制）
     */
    private function scheduleAsyncProcess($commentData, $delay)
    {
        // 创建一个标记文件，包含处理时间和评论数据
        $scheduleFile = __DIR__ . '/schedule_' . $commentData['coid'] . '.json';
        $scheduleData = array(
            'commentData' => $commentData,
            'processTime' => time() + $delay,
            'created' => time()
        );
        
        file_put_contents($scheduleFile, json_encode($scheduleData));
        
        // 触发后台处理（不阻塞）
        $this->triggerBackgroundProcess();
    }
    
    /**
     * 触发后台处理
     */
    private function triggerBackgroundProcess()
    {
        // 使用 fsockopen 触发异步请求
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
     * 处理计划任务
     */
    public function processScheduledTasks()
    {
        $scheduleDir = __DIR__;
        $files = glob($scheduleDir . '/schedule_*.json');
        
        if (empty($files)) {
            return;
        }
        
        $now = time();
        
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            
            if (!$data || !isset($data['processTime'])) {
                @unlink($file);
                continue;
            }
            
            // 检查是否到期
            if ($data['processTime'] <= $now) {
                try {
                    // 处理评论
                    $this->processComment($data['commentData'], true);
                    CommentAI_Plugin::log('已处理延迟任务: ' . $data['commentData']['coid']);
                } catch (Exception $e) {
                    CommentAI_Plugin::log('处理延迟任务失败: ' . $e->getMessage());
                }
                
                // 删除任务文件
                @unlink($file);
            }
        }
    }

    /**
     * 从队列中发布回复
     */
    public function publishFromQueue($queueId)
    {
        // 获取队列记录
        $queue = $this->db->fetchRow($this->db->select()
            ->from($this->prefix . 'comment_ai_queue')
            ->where('id = ?', $queueId)
        );

        if (!$queue) {
            throw new Exception('队列记录不存在');
        }
        
        $queue = (object)$queue; // 转换为对象

        // 获取评论信息
        $comment = $this->getCommentDetails($queue->cid);
        if (!$comment) {
            throw new Exception('原评论不存在');
        }

        // 发布回复
        $this->publishReply($comment, $queue->ai_reply);

        // 更新队列状态
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
        
        // 转换为对象数组
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
            $row = (object)$row; // 转换为对象
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
