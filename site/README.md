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

# 4. 建库 + 迁移（SQLite 库文件需自己 touch，否则注册/登录直接 500）
touch database/database.sqlite
php artisan migrate

# 5. 启动
php artisan serve --host=127.0.0.1 --port=8000
```

本机 composer 未装 PATH，装在 `"$APPDATA/composer/composer.phar"`（Composer 2.8.5）。删/加 `app/Providers/*.php` 后须 `dump-autoload` 重生成 classmap。

**本机环境现状**（2026-10-06）：PHP 8.2.33（全扩展齐）、node v24.19.0、**无 MySQL** → 本地用 SQLite、**无 nginx** → 用 `php artisan serve`。生产按 PRD §7 上 Nginx + PHP-FPM + MySQL。

## 路由

| 路由 | 说明 |
|---|---|
| `/` | 情报流：报告列表 + 全文检索（`?q=`） |
| `/reports/{period}` | 报告详情：摘要 / 核心矛盾 / 结构性发现 / 趋势预测 / 数据台账 / 数据溯源 |
| `/register` `/login` `/forgot-password` `/reset-password/{token}` `/logout` | 认证（guest/auth 分流） |
| `/subscription` | 订阅设置：兴趣标签（领域 × 地区），需登录 |
| `/briefing` | 我的情报：按兴趣标签命中的期数，需登录 |

## 认证与订阅（M5）

用户体系：`users` / `password_reset_tokens` / `subscriptions` 三张表（migrations），注册即登录、不发验证邮件（站内无 SMTP 收件场景）。

订阅标签的两条轴都复用内核的既有分类，不在站侧重造：
`SubscriptionService::DOMAINS` 与内核 `pipeline/modules/p3_extract.py` 的 `CATEGORY_KEYWORDS` 九类一致，`REGIONS` 与台账 `DataPoint.region` 的取值一致（全国 / 地区 / 县域 + 31 个省级裸名）。期数侧的标签由内核 `p5_publish._tags()` 写进 `report.json` 的 `domains` / `regions`。

匹配规则：两条轴 **AND**、轴内 **OR**；某轴不选即视为不限。`briefingsFor()` 在每条命中的摘要上附 `matched` 键标明具体命中项，卡片高亮命中标签。

保存时经 `normalize()` 过滤：不在候选表的值丢弃、去重保序，脏输入不入库。每用户一条订阅，upsert 更新。

推送通道未接：目前是「我的情报」拉取式浏览，因此 `subscriptions` 表暂未建 `channel` / `freq` 列，推送落地时增量 migration 即可。

## 数据读取

`app/Services/PublicationService.php` 只读内核产物 JSON，**不入库**：

```
../pipeline/data/<period>/
├── report.json     报告元数据（title / summary / source_count / domains / regions）
├── report.md       深度报告（文件交付物，不在站渲染）
├── analysis.json   三维交叉研判（P4）
└── ledger.json     数据台账（P3）
```

产物目录由 `LANTAI_DATA_DIR` 指定（留空默认 `../pipeline/data`）。无 `report.json` 的期数（半成品）自动跳过。

`LANTAI_LEDGER_PREVIEW`（默认 60）控制报告详情页台账预览条数——月报台账可达数百条，全量渲染会让研判正文被表格淹没，完整台账留在内核产物 `report.md`。`LANTAI_PER_PAGE`（默认 12）控制情报流每页篇数。

新增字段时改内核 `pipeline/contracts.py`，站端不解析 Markdown——`report.md` 是文件交付物，站渲染结构化 JSON，避免多套同步链路。

## 页面映射（Signify → 兰台观局，PRD §5.3）

| Signify | 兰台观局 |
|---|---|
| `entrepreneurs/index` | **情报流** `publications/index` |
| `entrepreneurs/show` | **报告详情** `publications/show` |
| `auth/*` | **已重建**（M5）：注册 / 登录 / 找回密码，`routes/auth.php` |
| `profile/edit` | **订阅设置** `subscription/edit`（字段换兴趣标签） |
| —（新增） | **我的情报** `briefing/index` |
| `admin/*` | 情报后台，未建（PRD §3.7） |

新建 3 组件（PRD §5.4）：`components/report-card`（无封面，大标题 + 眉标日期做主视觉）、`data-table`（仅 hairline 分隔、`tabular-nums`、表后读法）、`badge`。

## 测试

```bash
php vendor/bin/phpunit
```

套件：`tests/Feature/SubscriptionTest`（注册/登录/退出 + 标签保存与脏输入过滤）、`tests/Feature/BriefingTest`（认证闸门 + 两轴匹配 + 命中渲染）。BriefingTest 用假的内核产物（写进 `storage/testing/data`，跑完删除）覆盖整条匹配链路，不依赖真实 `data/` 目录。数据库走 sqlite `:memory:` + RefreshDatabase。

## 变更须知

删/加 `app/Providers/*.php` 后必须重生成 autoload（`optimize-autoloader: true` 会固化 classmap），否则启动报 "Class not found"。
