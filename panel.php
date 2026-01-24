<?php
/**
 * 后台管理面板
 * 
 * @package CommentAI
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

if (!defined('__TYPECHO_ADMIN__')) {
    define('__TYPECHO_ADMIN__', true);
}

require_once dirname(__DIR__) . '/../../admin/common.php';
require_once dirname(__DIR__) . '/../../admin/header.php';
require_once dirname(__DIR__) . '/../../admin/menu.php';

$config = Helper::options()->plugin('CommentAI');
$db = Typecho_Db::get();
$prefix = $db->getPrefix();

require_once __DIR__ . '/ReplyManager.php';
$manager = new CommentAI_ReplyManager($config);

// 自动处理延迟队列
$manager->processDelayedQueue();

// 处理操作请求
$do = isset($_GET['do']) ? $_GET['do'] : null;
if ($do && Typecho_Widget::widget('Widget_User')->pass('administrator', true)) {
    try {
        switch ($do) {
            case 'test':
                require_once __DIR__ . '/AIService.php';
                $aiService = new CommentAI_AIService($config);
                $result = $aiService->testConnection();
                if ($result['success']) {
                    Typecho_Widget::widget('Widget_Notice')->set(
                        '✅ ' . $result['message'] . '<br><strong>测试回复：</strong>' . htmlspecialchars($result['reply']),
                        'success'
                    );
                } else {
                    Typecho_Widget::widget('Widget_Notice')->set('❌ ' . $result['message'], 'error');
                }
                break;
            
            case 'clean':
                $days = isset($_GET['days']) ? intval($_GET['days']) : 30;
                $manager->cleanOldQueue($days);
                Typecho_Widget::widget('Widget_Notice')->set("✅ 已清理 {$days} 天前的旧记录", 'success');
                break;
            
            case 'publish':
                $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
                if ($id) {
                    $manager->publishFromQueue($id);
                    Typecho_Widget::widget('Widget_Notice')->set('✅ 回复已发布', 'success');
                }
                break;
            
            case 'reject':
                $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
                if ($id) {
                    $manager->rejectFromQueue($id, '人工拒绝');
                    Typecho_Widget::widget('Widget_Notice')->set('✅ 已拒绝该回复', 'success');
                }
                break;
            
            case 'regenerate':
                $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
                if ($id) {
                    $queue = $db->fetchRow($db->select()->from($prefix . 'comment_ai_queue')->where('id = ?', $id));
                    if ($queue) {
                        $comment = $db->fetchRow($db->select()->from($prefix . 'comments')->where('coid = ?', $queue['cid']));
                        if ($comment) {
                            $manager->processComment(array(
                                'coid' => $comment['coid'],
                                'author' => $comment['author'],
                                'text' => $comment['text'],
                                'status' => $comment['status'],
                                'type' => $comment['type'],
                                'parent' => $comment['parent'],
                                'cid' => $comment['cid']
                            ));
                            Typecho_Widget::widget('Widget_Notice')->set('✅ 已重新生成回复', 'success');
                        }
                    }
                }
                break;
        }
    } catch (Exception $e) {
        Typecho_Widget::widget('Widget_Notice')->set('❌ 操作失败: ' . $e->getMessage(), 'error');
    }
}

// 获取统计信息
$stats = $manager->getQueueStats();

// 获取当前页码和状态筛选
$currentPage = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$statusFilter = isset($_GET['status']) ? $_GET['status'] : null;

// 获取队列列表
$queueList = $manager->getQueueList($statusFilter, $currentPage, 20);
?>

<style>
.comment-ai-panel {
    padding: 20px;
}

/* 统计卡片 */
.stats-cards {
    display: flex;
    gap: 15px;
    margin-bottom: 30px;
    flex-wrap: wrap;
}
.stat-card {
    flex: 1;
    min-width: 150px;
    padding: 20px;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    text-align: center;
    transition: all 0.2s ease;
    border: 1px solid #eee;
}
.stat-card:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}
.stat-card .number {
    font-size: 32px;
    font-weight: bold;
    color: #467b96;
    margin: 10px 0;
}
.stat-card .label {
    color: #666;
    font-size: 14px;
}
.stat-card.pending .number { color: #f39c12; }
.stat-card.published .number { color: #27ae60; }
.stat-card.rejected .number { color: #e74c3c; }
.stat-card.error .number { color: #c0392b; }

/* 工具栏 */
.toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
    padding: 20px;
    background: #f5f5f5;
    border-radius: 8px;
    border: 1px solid #ddd;
}
.toolbar-left, .toolbar-right {
    display: flex;
    gap: 12px;
}

/* 按钮样式 */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border: 1px solid #ddd;
    border-radius: 6px;
    cursor: pointer;
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.2s ease;
    background: #fff;
}
.btn:hover {
    border-color: #999;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.btn-icon {
    font-size: 16px;
    line-height: 1;
}
.btn-text {
    color: #333;
}

/* 工具栏按钮特殊样式 */
.btn-refresh {
    color: #666;
}
.btn-refresh:hover {
    background: #fafafa;
}
.btn-clean {
    color: #e67e22;
}
.btn-clean:hover {
    background: #fef5f1;
    border-color: #e67e22;
}
.btn-settings {
    color: #666;
}
.btn-settings:hover {
    background: #fafafa;
}
.btn-test {
    color: #27ae60;
}
.btn-test:hover {
    background: #f0f9f4;
    border-color: #27ae60;
}

/* 操作按钮样式 */
.btn-primary {
    color: #fff;
    border-color: #555;
}
.btn-primary:hover {
    border-color: #333;
}
.btn-success {
    background: #27ae60;
    color: #fff;
    border-color: #27ae60;
}
.btn-success:hover {
    background: #229954;
    border-color: #229954;
}
.btn-danger {
    background: #e74c3c;
    color: #fff;
    border-color: #e74c3c;
}
.btn-danger:hover {
    background: #c0392b;
    border-color: #c0392b;
}
.btn-warning {
    background: #f39c12;
    color: #fff;
    border-color: #f39c12;
}
.btn-warning:hover {
    background: #e67e22;
    border-color: #e67e22;
}

/* 筛选标签 */
.filter-tabs {
    margin-bottom: 20px;
    border-bottom: 2px solid #eee;
}
.filter-tabs a {
    display: inline-block;
    padding: 12px 24px;
    margin-right: 10px;
    text-decoration: none;
    color: #666;
    border-bottom: 3px solid transparent;
    transition: all 0.2s;
    font-weight: 500;
}
.filter-tabs a:hover {
    color: #333;
    background: #f9f9f9;
}
.filter-tabs a.active {
    color: #333;
    border-bottom-color: #555;
    font-weight: bold;
}

/* 队列项 */
.queue-item {
    background: #fff;
    padding: 20px;
    margin-bottom: 15px;
    border-radius: 6px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border: 1px solid #eee;
    transition: all 0.2s ease;
}
.queue-item:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}
.queue-item-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
}
.queue-item-info {
    font-size: 14px;
    color: #666;
}
.queue-item-info strong {
    color: #333;
}
.status-badge {
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: bold;
}
.status-badge.pending { background: #fff3cd; color: #856404; }
.status-badge.published { background: #d4edda; color: #155724; }
.status-badge.rejected { background: #f8d7da; color: #721c24; }
.status-badge.error { background: #f8d7da; color: #721c24; }
.status-badge.suggest { background: #d1ecf1; color: #0c5460; }

/* 内容框 */
.comment-box, .reply-box {
    padding: 15px;
    margin: 10px 0;
    background: #f8f9fa;
    border-left: 4px solid #999;
    border-radius: 4px;
}
.reply-box {
    border-left-color: #27ae60;
}
.error-box {
    padding: 15px;
    margin: 10px 0;
    background: #f8d7da;
    border-left: 4px solid #e74c3c;
    border-radius: 4px;
    color: #721c24;
}

/* 操作按钮组 */
.action-buttons {
    display: flex;
    gap: 10px;
    margin-top: 15px;
    flex-wrap: wrap;
}

/* 空状态 */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: #fff;
    border-radius: 6px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border: 1px solid #eee;
}
.empty-state-icon {
    font-size: 64px;
    margin-bottom: 20px;
    opacity: 0.3;
}
</style>

<div class="comment-ai-panel">
    <div class="typecho-page-title">
        <h2>🤖 AI评论回复管理</h2>
    </div>

    <!-- 统计卡片 -->
    <div class="stats-cards">
        <div class="stat-card pending">
            <div class="label">待审核</div>
            <div class="number"><?php echo $stats['pending']; ?></div>
        </div>
        <div class="stat-card published">
            <div class="label">已发布</div>
            <div class="number"><?php echo $stats['published']; ?></div>
        </div>
        <div class="stat-card rejected">
            <div class="label">已拒绝</div>
            <div class="number"><?php echo $stats['rejected']; ?></div>
        </div>
        <div class="stat-card">
            <div class="label">仅建议</div>
            <div class="number"><?php echo $stats['suggest']; ?></div>
        </div>
        <div class="stat-card error">
            <div class="label">错误</div>
            <div class="number"><?php echo $stats['error']; ?></div>
        </div>
        <div class="stat-card">
            <div class="label">总计</div>
            <div class="number"><?php echo $stats['total']; ?></div>
        </div>
    </div>

    <!-- 工具栏 -->
    <div class="toolbar">
        <div class="toolbar-left">
            <a href="<?php echo Helper::options()->adminUrl . 'extending.php?panel=CommentAI%2Fpanel.php'; ?>" 
               class="btn btn-refresh">
               <span class="btn-icon">🔄</span>
               <span class="btn-text">刷新</span>
            </a>
            <a href="<?php echo Helper::security()->getTokenUrl(Helper::options()->adminUrl . 'extending.php?panel=CommentAI%2Fpanel.php&do=clean&days=30'); ?>" 
               class="btn btn-clean" 
               onclick="return confirm('确定要清理30天前的旧记录吗？');">
               <span class="btn-icon">🧹</span>
               <span class="btn-text">清理旧记录</span>
            </a>
        </div>
        <div class="toolbar-right">
            <a href="<?php echo Helper::options()->adminUrl . 'options-plugin.php?config=CommentAI'; ?>" 
               class="btn btn-settings">
               <span class="btn-icon">⚙️</span>
               <span class="btn-text">插件设置</span>
            </a>
            <a href="<?php echo Helper::security()->getTokenUrl(Helper::options()->adminUrl . 'extending.php?panel=CommentAI%2Fpanel.php&do=test'); ?>" 
               class="btn btn-test">
               <span class="btn-icon">🧪</span>
               <span class="btn-text">测试连接</span>
            </a>
        </div>
    </div>

    <!-- 状态筛选 -->
    <div class="filter-tabs">
        <a href="?panel=CommentAI%2Fpanel.php" class="<?php echo is_null($statusFilter) ? 'active' : ''; ?>">
            全部 (<?php echo $stats['total']; ?>)
        </a>
        <a href="?panel=CommentAI%2Fpanel.php&status=pending" class="<?php echo $statusFilter == 'pending' ? 'active' : ''; ?>">
            待审核 (<?php echo $stats['pending']; ?>)
        </a>
        <a href="?panel=CommentAI%2Fpanel.php&status=published" class="<?php echo $statusFilter == 'published' ? 'active' : ''; ?>">
            已发布 (<?php echo $stats['published']; ?>)
        </a>
        <a href="?panel=CommentAI%2Fpanel.php&status=rejected" class="<?php echo $statusFilter == 'rejected' ? 'active' : ''; ?>">
            已拒绝 (<?php echo $stats['rejected']; ?>)
        </a>
        <a href="?panel=CommentAI%2Fpanel.php&status=suggest" class="<?php echo $statusFilter == 'suggest' ? 'active' : ''; ?>">
            仅建议 (<?php echo $stats['suggest']; ?>)
        </a>
        <a href="?panel=CommentAI%2Fpanel.php&status=error" class="<?php echo $statusFilter == 'error' ? 'active' : ''; ?>">
            错误 (<?php echo $stats['error']; ?>)
        </a>
    </div>

    <!-- 队列列表 -->
    <?php if (empty($queueList)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">📭</div>
            <h3>暂无记录</h3>
            <p style="color:#999;">当有新评论时，AI将自动生成回复并显示在这里</p>
        </div>
    <?php else: ?>
        <?php foreach ($queueList as $item): ?>
            <div class="queue-item">
                <div class="queue-item-header">
                    <div class="queue-item-info">
                        <strong><?php echo htmlspecialchars($item->comment_author); ?></strong> 
                        在 
                        <a href="<?php 
                            $post = $db->fetchRow($db->select()->from($prefix . 'contents')->where('cid = ?', $item->post_id));
                            if ($post) {
                                $post = (object)$post;
                                echo Helper::options()->siteUrl . $post->slug . '.html#comment-' . $item->cid;
                            } else {
                                echo '#';
                            }
                        ?>" target="_blank">
                            <?php 
                                if ($post) {
                                    echo htmlspecialchars($post->title); 
                                } else {
                                    echo '文章已删除';
                                }
                            ?>
                        </a> 
                        发表了评论
                        <span style="color:#999;margin-left:10px;">
                            <?php echo date('Y-m-d H:i:s', $item->created_at); ?>
                        </span>
                    </div>
                    <span class="status-badge <?php echo $item->status; ?>">
                        <?php 
                            $statusText = array(
                                'pending' => '⏳ 待审核',
                                'published' => '✅ 已发布',
                                'rejected' => '❌ 已拒绝',
                                'suggest' => '💡 仅建议',
                                'error' => '⚠️ 错误'
                            );
                            echo isset($statusText[$item->status]) ? $statusText[$item->status] : $item->status;
                        ?>
                    </span>
                </div>

                <div class="comment-box">
                    <strong>💬 读者评论：</strong><br>
                    <?php echo nl2br(htmlspecialchars($item->comment_text)); ?>
                </div>

                <?php if (!empty($item->ai_reply)): ?>
                    <div class="reply-box">
                        <strong>🤖 AI回复：</strong><br>
                        <?php echo nl2br(htmlspecialchars($item->ai_reply)); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($item->error_msg)): ?>
                    <div class="error-box">
                        <strong>⚠️ 错误信息：</strong><br>
                        <?php echo htmlspecialchars($item->error_msg); ?>
                    </div>
                <?php endif; ?>

                <div class="action-buttons">
                    <?php if ($item->status == 'pending' || $item->status == 'suggest'): ?>
                        <a href="<?php echo Helper::security()->getTokenUrl(Helper::options()->adminUrl . 'extending.php?panel=CommentAI%2Fpanel.php&do=publish&id=' . $item->id); ?>" 
                           class="btn btn-success" 
                           onclick="return confirm('确定要发布这条AI回复吗？');">
                            <span class="btn-icon">✅</span>
                            <span class="btn-text">发布回复</span>
                        </a>
                        <a href="<?php echo Helper::security()->getTokenUrl(Helper::options()->adminUrl . 'extending.php?panel=CommentAI%2Fpanel.php&do=reject&id=' . $item->id); ?>" 
                           class="btn btn-danger" 
                           onclick="return confirm('确定要拒绝这条AI回复吗？');">
                            <span class="btn-icon">❌</span>
                            <span class="btn-text">拒绝回复</span>
                        </a>
                    <?php endif; ?>
                    
                    <?php if ($item->status == 'error' || $item->status == 'rejected'): ?>
                        <a href="<?php echo Helper::security()->getTokenUrl(Helper::options()->adminUrl . 'extending.php?panel=CommentAI%2Fpanel.php&do=regenerate&id=' . $item->id); ?>" 
                           class="btn btn-warning">
                            <span class="btn-icon">🔄</span>
                            <span class="btn-text">重新生成</span>
                        </a>
                    <?php endif; ?>

                    <a href="<?php echo Helper::options()->adminUrl . 'manage-comments.php?coid=' . $item->cid; ?>" 
                       class="btn btn-primary" 
                       target="_blank">
                        <span class="btn-icon">👁️</span>
                        <span class="btn-text">查看原评论</span>
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php
require_once dirname(__DIR__) . '/../../admin/copyright.php';
require_once dirname(__DIR__) . '/../../admin/footer.php';
?>
