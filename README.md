# CommentAI - Typecho AI 智能评论回复插件

<div align="center">

让 AI 成为你的评论助手，自动生成高质量的回复内容

[![Typecho](https://img.shields.io/badge/Typecho-1.2.1%20%7C%201.3.0-blue.svg)](http://typecho.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

</div>

---

## 环境要求

- Typecho **1.2.1** 或 **1.3.0**
- PHP **8.0+**，需开启 `curl` 扩展
- MySQL 5.7+/8.0+ 或 SQLite

---

## 安装

1. 将本仓库下载或克隆到 Typecho 插件目录，文件夹名必须为 `CommentAI`：

```
usr/plugins/CommentAI/
```

2. 登录后台 → 控制台 → 插件，启用 **CommentAI**
3. 进入插件设置，选择 AI 平台并填写 API Key、模型名称
4. 建议先用管理面板里的「测试连接」，确认接口可用后再打开全自动回复

---

## 从 1.3.0 升级到 1.4.0

数据库表结构没有变化，旧队列数据可继续使用。但 **必须先禁用再启用插件**，否则新钩子不会生效。

1. 后台禁用 CommentAI，再重新启用
2. 打开插件设置，确认 AI 平台和模型后保存一次
3. 以下配置已移除，保存后会自动忽略，无需手动清理：
   - 批量合并（`batchWindow`）
   - 回复延迟（`replyDelay`）
   - 仅建议模式
   - 仅对文章第一条评论回复
   - 低价值评论的「精简调用」
   - 忽略 trackback 开关（现已默认忽略引用类型）

---

## 功能特性

- **全自动 / 人工审核**：生成后直接发布，或先进入后台队列
- **多平台接入**：阿里云百炼、OpenAI、DeepSeek、Kimi、智谱 GLM、火山引擎豆包、硅基流动、Gemini、Claude、OpenRouter、Groq、xAI Grok、Ollama、自定义 OpenAI 兼容接口
- **上下文感知**：文章标题、摘要、最多 10 层评论链
- **低价值过滤**：命中「感谢」「666」等关键词时使用固定回复，不消耗 API
- **审核后回复**：开启「仅对已审核的评论回复」时，后台点「通过」才会生成
- **不阻塞评论**：访客提交后立刻返回，AI 在后台生成
- **管理面板**：审核队列、发布 / 拒绝 / 重新生成、运行日志

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
| AI 服务提供商 | 见下方平台与模型示例 |
| API Key | AI 服务密钥（Ollama 可留空） |
| API 地址 | 自定义端点，留空使用各平台默认地址 |
| 模型名称 | 必须填写平台对应的模型标识 |

| 平台 | 默认 API 地址 | 模型示例 |
|------|----------------|----------|
| 阿里云百炼 | `https://dashscope.aliyuncs.com/compatible-mode/v1` | `qwen-plus` |
| OpenAI | `https://api.openai.com/v1` | `gpt-5-mini` |
| DeepSeek | `https://api.deepseek.com/v1` | `deepseek-chat` |
| Kimi | `https://api.moonshot.cn/v1` | `kimi-k2` |
| 智谱 GLM | `https://open.bigmodel.cn/api/paas/v4` | `glm-4.5` |
| 火山引擎豆包 | `https://ark.cn-beijing.volces.com/api/v3` | 控制台中的接入点 ID |
| 硅基流动 | `https://api.siliconflow.cn/v1` | `Qwen/Qwen3-8B` |
| Google Gemini | Gemini 原生接口 | `gemini-2.5-flash` |
| Anthropic Claude | `https://api.anthropic.com` | `claude-sonnet-5` |
| OpenRouter | `https://openrouter.ai/api/v1` | `openai/gpt-5-mini` |
| Groq | `https://api.groq.com/openai/v1` | `llama-3.3-70b-versatile` |
| xAI Grok | `https://api.x.ai/v1` | `grok-4` |
| Ollama | `http://127.0.0.1:11434/v1` | `llama3.2` |

OpenAI 的 GPT-5 等推理模型会自动改用 `max_completion_tokens`，国内兼容接口仍发送 `max_tokens`。Gemini 2.5 会关闭 thinking，避免思考 token 占满输出额度。

### Prompt 配置

| 配置项 | 说明 |
|--------|------|
| 系统提示词 | 定义 AI 的角色和回复风格 |
| 上下文信息 | 可选：文章标题、文章摘要（前 300 字）、父级评论（含完整评论链） |

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

引用（trackback / pingback）始终忽略。管理员自己的评论也不会触发 AI。

---

## 工作流程

```
访客发表评论
    │
    ├─ 未审核且开启了「仅已审核」→ 等待后台通过
    ├─ 垃圾评论 / 引用 / 管理员评论 → 跳过
    └─ 通过过滤 → 后台异步调用 AI（不卡住评论提交）
                    │
                    ├─ 全自动 → 直接以博主身份发布回复
                    └─ 人工审核 → 进入插件面板，手动发布或拒绝
```

兼容 Typecho 1.2.1 与 1.3.0：前台提交、后台回复、后台审核通过都会进入同一套处理。1.3.0 使用官方异步服务；1.2.1 使用支持 HTTPS 的短超时请求。

---

## 数据库

插件启用时自动创建 `comment_ai_queue` 表，支持 MySQL 5.7+/8.0+ 和 SQLite。若自动建表失败，可手动执行 `install.sql`（按实际表前缀替换 `typecho_`）。

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
