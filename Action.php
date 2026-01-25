<?php
/**
 * 后台管理动作处理器
 * 
 * @package CommentAI
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class CommentAI_Action extends Typecho_Widget implements Widget_Interface_Do
{
    private $db;
    private $prefix;
    private $config;

    public function __construct($request, $response, $params = NULL)
    {
        parent::__construct($request, $response, $params);
        $this->db = Typecho_Db::get();
        $this->prefix = $this->db->getPrefix();
        $this->config = Helper::options()->plugin('CommentAI');
    }

    public function action()
    {
        // 处理计划任务不需要管理员权限
        if ($this->request->is('do=process_scheduled')) {
            $this->processScheduled();
            return;
        }
        
        $this->widget('Widget_User')->pass('administrator');
        $this->on($this->request->is('do=test'))->testConnection();
        $this->on($this->request->is('do=publish'))->publishReply();
        $this->on($this->request->is('do=reject'))->rejectReply();
        $this->on($this->request->is('do=batch'))->batchProcess();
        $this->on($this->request->is('do=regenerate'))->regenerateReply();
        $this->on($this->request->is('do=clean'))->cleanQueue();
        $this->response->goBack();
    }
    
    /**
     * 处理计划任务
     */
    public function processScheduled()
    {
        try {
            require_once __DIR__ . '/ReplyManager.php';
            $manager = new CommentAI_ReplyManager($this->config);
            $manager->processScheduledTasks();
            
            // 返回成功（不跳转）
            echo 'OK';
        } catch (Exception $e) {
            CommentAI_Plugin::log('处理计划任务失败: ' . $e->getMessage());
            echo 'ERROR';
        }
        exit;
    }

    /**
     * 测试AI连接
     */
    public function testConnection()
    {
        try {
            require_once __DIR__ . '/AIService.php';
            $aiService = new CommentAI_AIService($this->config);
            $result = $aiService->testConnection();
            
            if ($result['success']) {
                $this->widget('Widget_Notice')->set(
                    '✅ ' . $result['message'] . '<br><strong>测试回复：</strong>' . htmlspecialchars($result['reply']),
                    'success'
                );
            } else {
                $this->widget('Widget_Notice')->set('❌ ' . $result['message'], 'error');
            }
        } catch (Exception $e) {
            $this->widget('Widget_Notice')->set('❌ 测试失败: ' . $e->getMessage(), 'error');
        }

        $this->response->goBack();
    }

    /**
     * 发布回复
     */
    public function publishReply()
    {
        $id = $this->request->get('id');
        if (!$id) {
            $this->widget('Widget_Notice')->set('参数错误', 'error');
            $this->response->goBack();
        }

        try {
            require_once __DIR__ . '/ReplyManager.php';
            $manager = new CommentAI_ReplyManager($this->config);
            $manager->publishFromQueue($id);
            
            $this->widget('Widget_Notice')->set('✅ 回复已发布', 'success');
        } catch (Exception $e) {
            $this->widget('Widget_Notice')->set('❌ 发布失败: ' . $e->getMessage(), 'error');
        }

        $this->response->goBack();
    }

    /**
     * 拒绝回复
     */
    public function rejectReply()
    {
        $id = $this->request->get('id');
        if (!$id) {
            $this->widget('Widget_Notice')->set('参数错误', 'error');
            $this->response->goBack();
        }

        try {
            require_once __DIR__ . '/ReplyManager.php';
            $manager = new CommentAI_ReplyManager($this->config);
            $manager->rejectFromQueue($id, '人工拒绝');
            
            $this->widget('Widget_Notice')->set('✅ 已拒绝该回复', 'success');
        } catch (Exception $e) {
            $this->widget('Widget_Notice')->set('❌ 操作失败: ' . $e->getMessage(), 'error');
        }

        $this->response->goBack();
    }

    /**
     * 批量处理
     */
    public function batchProcess()
    {
        $ids = $this->request->get('ids');
        $action = $this->request->get('action');

        if (!$ids || !$action) {
            $this->widget('Widget_Notice')->set('参数错误', 'error');
            $this->response->goBack();
        }

        $ids = explode(',', $ids);

        try {
            require_once __DIR__ . '/ReplyManager.php';
            $manager = new CommentAI_ReplyManager($this->config);
            $result = $manager->batchProcess($ids, $action);
            
            $this->widget('Widget_Notice')->set(
                "✅ 批量操作完成：成功 {$result['success']} 条，失败 {$result['failed']} 条",
                'success'
            );
        } catch (Exception $e) {
            $this->widget('Widget_Notice')->set('❌ 批量操作失败: ' . $e->getMessage(), 'error');
        }

        $this->response->goBack();
    }

    /**
     * 重新生成回复
     */
    public function regenerateReply()
    {
        $id = $this->request->get('id');
        if (!$id) {
            $this->widget('Widget_Notice')->set('参数错误', 'error');
            $this->response->goBack();
        }

        try {
            // 获取队列记录
            $queue = $this->db->fetchRow($this->db->select()
                ->from($this->prefix . 'comment_ai_queue')
                ->where('id = ?', $id)
            );

            if (!$queue) {
                throw new Exception('队列记录不存在');
            }

            // 获取评论信息
            $comment = $this->db->fetchRow($this->db->select()
                ->from($this->prefix . 'comments')
                ->where('coid = ?', $queue->cid)
            );

            if (!$comment) {
                throw new Exception('原评论不存在');
            }

            // 重新生成
            require_once __DIR__ . '/ReplyManager.php';
            $manager = new CommentAI_ReplyManager($this->config);
            $manager->processComment(array(
                'coid' => $comment->coid,
                'author' => $comment->author,
                'text' => $comment->text,
                'status' => $comment->status,
                'type' => $comment->type,
                'parent' => $comment->parent,
                'cid' => $comment->cid
            ));
            
            $this->widget('Widget_Notice')->set('✅ 已重新生成回复', 'success');
        } catch (Exception $e) {
            $this->widget('Widget_Notice')->set('❌ 重新生成失败: ' . $e->getMessage(), 'error');
        }

        $this->response->goBack();
    }

    /**
     * 清理队列
     */
    public function cleanQueue()
    {
        $days = intval($this->request->get('days', 30));

        try {
            require_once __DIR__ . '/ReplyManager.php';
            $manager = new CommentAI_ReplyManager($this->config);
            $manager->cleanOldQueue($days);
            
            $this->widget('Widget_Notice')->set("✅ 已清理 {$days} 天前的旧记录", 'success');
        } catch (Exception $e) {
            $this->widget('Widget_Notice')->set('❌ 清理失败: ' . $e->getMessage(), 'error');
        }

        $this->response->goBack();
    }
}
