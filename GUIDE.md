

## 简介

CommentAI 是一个 Typecho 博客的 AI 智能评论回复插件。

**核心功能**

- 自动生成评论回复
- 支持通义千问、OpenAI、DeepSeek、Kimi 等模型设置
- 三种工作模式可选（自动回复、人工审核、仅建议）
- 支持上下文感知回复
- 支持敏感词过滤和频率限制
- 支持回复延迟
- 支持触发条件设置
- 支持AI标识显示

---

## 安装

### 环境要求

- Typecho 1.2.0 - 1.3.0
- PHP 7.0+（需要 curl、json、mbstring 扩展）
- MySQL 5.7+ 或 SQLite 3.0+
- AI 服务 API Key

### 安装步骤

1. 上传 `CommentAI` 文件夹到 `/usr/plugins/` 目录
2. 后台「控制台」→「插件」→ 启用「CommentAI」
3. 插件自动创建数据库表
4. 失败则手动执行****install.sql**文件

---

## 配置

### 基础配置

**插件开关**

- 启用/禁用插件

**回复模式**

- 全自动：AI 生成后直接发布
- 人工审核：生成后需人工审核
- 仅建议：仅作参考，不发布

**管理员 UID**

- AI 回复使用的用户身份，默认为 1

![2026-01-25T12:03:19.png](https://static.blog.ybyq.wang/usr/uploads/2026/01/25/2026-01-25T12:03:19.png?x-oss-process=style/shuiyin)


### AI 平台配置

**支持平台**

- 阿里云百炼：国内访问快，价格实惠
- OpenAI：质量高，需国际网络
- DeepSeek：性价比高
- Kimi（月之暗面）：国产大模型
- 自定义：支持 OpenAI 兼容接口

**API 配置**

- API Key：必填，在对应平台获取
- API 地址：可选，留空使用默认
- 模型名称：如 qwen-plus、gpt-4o-mini、deepseek-chat、moonshot-v1-8k

![2026-01-25T12:04:56.png](https://static.blog.ybyq.wang/usr/uploads/2026/01/25/2026-01-25T12:04:56.png?x-oss-process=style/shuiyin)


### Prompt 配置

**系统提示词**

定义 AI 的角色和回复风格：

```
你是一位友好、专业的博主。根据读者评论生成恰当的回复。

要求：
1. 语气自然、亲切
2. 针对评论内容给出有价值的回应
3. 对提问给出明确答案
4. 回复长度 50-150 字
5. 使用中文回复
```

**上下文信息**

- 包含文章标题
- 包含文章摘要（增加 Token 消耗）
- 包含父级评论

![2026-01-25T12:06:08.png](https://static.blog.ybyq.wang/usr/uploads/2026/01/25/2026-01-25T12:06:08.png?x-oss-process=style/shuiyin)


### 高级配置

**温度参数**

- 0.0-0.3：确定性强
- 0.4-0.7：平衡（推荐）
- 0.8-1.0：创造性强

**最大 Token 数**

- 建议 200-500

**敏感词过滤**

- 每行一个敏感词

**频率限制**

- 每小时最大调用次数，0 为不限制

**回复延迟**

- 延迟多少秒回复，0 为立即回复（推荐）
- 建议 30-120 秒

![2026-01-25T12:06:53.png](https://static.blog.ybyq.wang/usr/uploads/2026/01/25/2026-01-25T12:06:53.png?x-oss-process=style/shuiyin)


### 显示设置

**AI 标识**

- 显示：在回复末尾添加标识（推荐，可自定义）
- 不显示：看起来像人工回复

### 触发条件

- 仅对已审核的评论回复（推荐）
- 忽略垃圾评论（推荐）
- 忽略引用和 Trackback（推荐）
- 仅对文章的第一条评论回复
- 自动排除管理员评论

![2026-01-25T12:07:33.png](https://static.blog.ybyq.wang/usr/uploads/2026/01/25/2026-01-25T12:07:33.png?x-oss-process=style/shuiyin)


---

## 使用

### 工作流程

1. 读者发表评论
2. 插件检查触发条件
3. 延迟指定时间（如果配置了）
4. 调用 AI 生成回复
5. 根据模式处理

### 管理面板

访问：后台 → AI评论回复

**功能**

- 统计卡片：显示各状态数量
- 状态筛选：查看不同状态的回复
- 队列列表：查看详细信息
- 操作按钮：发布、拒绝、重新生成、查看原评论

**工具栏**

- 刷新：重新加载数据
- 清理旧记录：删除 30 天前的记录
- 插件设置：跳转到配置页面
- 测试连接：测试 AI 服务

![2026-01-25T12:08:49.png](https://static.blog.ybyq.wang/usr/uploads/2026/01/25/2026-01-25T12:08:49.png?x-oss-process=style/shuiyin)

---

## 最佳实践

### 提升回复质量

1. **优化系统提示词**
   - 明确角色定位
   - 详细描述风格
   - 设定长度范围

2. **合理配置上下文**
   - 技术博客：启用标题和摘要
   - 生活博客：仅启用标题

3. **调整温度参数**
   - 技术问答：0.3-0.5
   - 创意内容：0.7-0.9

### 控制成本

1. 设置频率限制
2. 优化 Token 消耗
3. 选择合适模型
4. 使用人工审核模式

### 安全建议

1. 保护 API Key
2. 配置敏感词
3. 保持透明



## 故障排查

### 插件激活失败

- 检查数据库权限
- 确认数据库类型

### AI 回复未生成

- 检查插件是否启用
- 检查触发条件设置
- 检查频率限制
- 确认不是管理员评论

### API 调用失败

- 验证 API Key
- 测试网络连接
- 检查 API 地址
- 确认模型名称
- 检查账户余额

### 查看日志

日志位置：`/usr/plugins/CommentAI/runtime.log`

```bash
tail -f /path/to/typecho/usr/plugins/CommentAI/runtime.log
```
![2026-01-25T12:09:57.png](https://static.blog.ybyq.wang/usr/uploads/2026/01/25/2026-01-25T12:09:57.png?x-oss-process=style/shuiyin)

---

## 卸载

1. 后台禁用插件
2. 删除插件文件夹
3. （可选）删除数据库表：

```sql
DROP TABLE IF EXISTS `typecho_comment_ai_queue`;
```

---
## 下载地址
Github：
[hide]
https://github.com/BXCQ/CommentAI
[/hide]


