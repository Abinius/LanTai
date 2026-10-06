# 兰台观局 · 前端站（Laravel 11 · Signify 风格）

读取 Python 内核（`../pipeline/`）产出的报告与台账渲染。**零 Docker**（PRD §2）。

设计系统直接继承 Signify：paper/ink/accent 令牌、Playfair + Inter 字栈、2px 圆角、hairline 细线、accent 朱红作印章（全页 < 5%）。

## 本地运行

```bash
# 1. 依赖（vendor/ 未入库，全新拉取时）
php "$APPDATA/composer/composer.phar" install

# 2. 环境
cp .env.example .env
php artisan key:generate

# 3. 前端产物（Tailwind 构建到 public/css/app.css）
export PATH="/c/Program Files/nodejs:$PATH"
npm install && npm run build:css

# 4. 启动
php artisan serve --host=127.0.0.1 --port=8000
```

本机 composer 未装 PATH，装在 `"$APPDATA/composer/composer.phar"`（Composer 2.8.5）。删/加 `app/Providers/*.php` 后须 `dump-autoload` 重生成 classmap。

**本机环境现状**（2026-10-06）：PHP 8.2.33（全扩展齐）、node v24.19.0、**无 MySQL** → 本地用 SQLite、**无 nginx** → 用 `php artisan serve`。生产按 PRD §7 上 Nginx + PHP-FPM + MySQL。

## 路由

| 路由 | 说明 |
|---|---|
| `/` | 情报流：报告列表 + 全文检索（`?q=`） |
| `/reports/{period}` | 报告详情：摘要 / 核心矛盾 / 结构性发现 / 趋势预测 / 数据台账 / 数据溯源 |

## 数据读取

`app/Services/PublicationService.php` 只读内核产物 JSON，**不入库**：

```
../pipeline/data/<period>/
├── report.json     报告元数据（title / summary / source_count）
├── report.md       深度报告（文件交付物，不在站渲染）
├── analysis.json   三维交叉研判（P4）
└── ledger.json     数据台账（P3）
```

产物目录由 `LANQTAI_DATA_DIR` 指定（留空默认 `../pipeline/data`）。无 `report.json` 的期数（半成品）自动跳过。

新增字段时改内核 `pipeline/contracts.py`，站端不解析 Markdown——`report.md` 是文件交付物，站渲染结构化 JSON，避免多套同步链路。

## 页面映射（Signify → 兰台观局，PRD §5.3）

| Signify | 兰台观局 |
|---|---|
| `entrepreneurs/index` | **情报流** `publications/index` |
| `entrepreneurs/show` | **报告详情** `publications/show` |
| `auth/*` / `admin/*` / `profile/*` | 已裁除，M5（Signify 订阅端）另建 |

新建 3 组件（PRD §5.4）：`components/report-card`（无封面，大标题 + 眉标日期做主视觉）、`data-table`（仅 hairline 分隔、`tabular-nums`、表后读法）、`badge`。

## 变更须知

删/加 `app/Providers/*.php` 后必须重生成 autoload（`optimize-autoloader: true` 会固化 classmap），否则启动报 "Class not found"。
