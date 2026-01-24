-- CommentAI 插件数据库表
-- 如果插件激活失败，可以手动执行此 SQL 文件

-- MySQL 5.7+ / 8.0+ 版本
CREATE TABLE IF NOT EXISTS `typecho_comment_ai_queue` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='AI评论回复队列';

-- 注意：
-- 1. 如果你的表前缀不是 typecho_，请将上面的 typecho_ 替换为你的表前缀
-- 2. 此插件仅支持 MySQL 5.7+/8.0+ 和 SQLite，不支持 PostgreSQL
-- 3. MySQL 5.7 用户如遇到字符集问题，可将 utf8mb4 改为 utf8
