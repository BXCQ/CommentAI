# CommentAI - Typecho AI 智能评论回复插件

<div align="center">

让 AI 成为你的评论助手，自动生成高质量的回复内容

[![Typecho](https://img.shields.io/badge/Typecho-1.2.1%20%7C%201.3.0-blue.svg)](http://typecho.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

</div>

---

## 功能特性

### 工作模式

- 全自动模式：AI 生成后直接发布
- 人工审核模式：生成后需后台审核（推荐）

### 多平台 AI 支持

- 阿里云百炼（通义千问 Qwen）
- OpenAI（GPT-5 等，自动使用 `max_completion_tokens`）
- DeepSeek
- Kimi（月之暗面）
- 智谱 GLM
- 火山引擎（豆包）
- 硅基流动 SiliconFlow
- Google Gemini
- Anthropic Claude
- OpenRouter
- Groq
- xAI Grok
- Ollama（本地）
- 自定义 OpenAI 兼容接口

### 上下文感知

- 读取文章标题和摘要
- 完整评论链追溯（向上追溯最多 10 层，区分人工回复与 AI 回复）
- 根据语境生成个性化回复

### 低价值评论过滤

- 关键词完全匹配 + 纯数字短内容检测（如 "1"、"666"）
- 命中后使用自定义固定回复，不调用 AI

### 安全防护

- 内置敏感词过滤
- API 调用频率限制
- 始终忽略 trackback / pingback
- 仅对已审核评论回复（待审评论在后台通过后才会生成）

### 透明化显示

- 可选 AI 标识（如 `🤖 AI辅助回复`）
- 符合伦理透明性原则

### 管理后台

- 可视化审核队列
- 一键发布 / 拒绝 / 重新生成
- 统计数据与运行日志

---

## 配置说明

### 基础配置

| 配置项 | 说明 |
|--------|------|
| 插件开关 | 启用/禁用插件 |
| 回复模式 | 全自动 / 人工审核 |
| 管理员 UID | AI 回复将以该用户身份发布 |

### AI 平台配置

| 配置项 | 说明 |
|--------|------|
| AI 服务提供商 | 见上方平台列表 |
| API Key | AI 服务密钥（Ollama 可留空） |
| API 地址 | 自定义端点，留空使用各平台默认地址 |
| 模型名称 | 如 `qwen-plus`、`gpt-5-mini`、`deepseek-chat`、`kimi-k2`、`glm-4.5`、`gemini-2.5-flash`、`claude-sonnet-5` |

### Prompt 配置

| 配置项 | 说明 |
|--------|------|
| 系统提示词 | 定义 AI 的角色和回复风格 |
| 上下文信息 | 可选：文章标题、文章摘要（前 300 字）、父级评论（含完整评论链追溯） |

### 高级配置

| 配置项 | 说明 |
|--------|------|
| 温度参数 | 0-1，越高越随机，建议 0.7-0.9 |
| 最大 Token 数 | 单次回复最大长度，建议 200-500 |
| 敏感词过滤 | 每行一个，AI 回复包含则拦截 |
| 每小时最大调用次数 | 防止 API 费用失控，0 为不限制 |

### 低价值评论过滤

| 配置项 | 说明 |
|--------|------|
| 低价值评论检测 | 启用后命中关键词则使用固定回复，不调用 AI |
| 低价值关键词 | 每行一个，评论完全匹配时触发 |
| 固定回复 | 自定义回复内容，支持 HTML |

### 显示设置

| 配置项 | 说明 |
|--------|------|
| AI 标识显示 | 是否在回复后追加 AI 标识 |
| AI 标识文本 | 自定义标识内容 |

### 触发条件

| 配置项 | 说明 |
|--------|------|
| 仅对已审核的评论回复 | 待审评论在后台点「通过」后才会生成 AI 回复 |
| 忽略垃圾评论 | 跳过 spam 状态评论 |

---

## 运行逻辑

兼容 Typecho 1.2.1 与 1.3.0：

```
访客评论 → Widget_Feedback::finishComment
后台回复 → Widget_Comments_Edit::finishComment
后台通过 → Widget_Comments_Edit::mark
                │
        条件过滤（管理员 / 未审核 / spam / 频率）
                │
        写入计划任务（不阻塞评论提交）
                │
        Typecho 1.3：Helper::requestService
        Typecho 1.2.1：HTTPS 短超时 curl
                │
        构建上下文（含评论链） → 调用 AI
                │
        敏感词过滤
                │
        auto → 直接发布
        audit → 入队列 pending（待审核）
                │
        后台面板：发布 / 拒绝 / 重新生成
```

AI 回复写入评论表时，`ownerId` 为文章作者，`created` 使用站点时区时间，评论数使用原子递增，与 Typecho 核心行为一致。

---

## 数据库

插件自动创建 `comment_ai_queue` 表，支持 MySQL 5.7+/8.0+ 和 SQLite。表结构无变更，升级兼容旧版本数据。

手动建表可执行 `install.sql`。

---

## 项目结构

```
CommentAI/
├── Plugin.php              # 插件主文件（钩子注册、配置面板）
├── AIService.php           # AI 服务工厂
├── ReplyManager.php        # 回复管理器（异步调度、评论链、队列）
├── Action.php              # 后台动作处理器
├── panel.php               # 后台管理面板
├── install.sql             # 手动建表 SQL
├── providers/
│   ├── BaseProvider.php    # Provider 抽象基类
│   ├── OpenAIProvider.php  # OpenAI 兼容适配器
│   ├── GeminiProvider.php  # Google Gemini 原生 API
│   └── ClaudeProvider.php  # Anthropic Claude 原生 API
```

---

## 开源协议

本项目采用 [MIT License](LICENSE) 开源协议。

---

如果觉得这个插件有用，请给个 Star 支持一下！
