# 兰台观局

以国字号舆论场与官方宏观数据为信源，经「采集 → 抽取 → 三维交叉研判 → 出刊」五阶段流水线，产出**少而准**的政策判断。

- **出品**：兰台观局 ｜ **分发**：松山高参 ｜ **品牌语**：兰台之上，静观天下局
- **内核**：Python 批处理（五阶段，标准库为主，采集零依赖）
- **站点**：Laravel 11 + Blade + Alpine.js + Tailwind（Signify 风格）
- **数据**：产物 JSON 只读，不入站库
- **部署**：零 Docker（Nginx + PHP-FPM + MySQL + cron + systemd）

## 当前状态

**M1–M5 全部交付（2026-10-06），部署脚本就绪。**

| 里程碑 | 状态 | 内容 |
|---|---|---|
| M1 内核 MVP | ✅ | p1 区间 + p2 双适配器（新闻联播/人民日报）+ p3 抽取 → 台账 |
| M2 研判 | ✅ | p4 三维交叉研判（数据 × 官媒 × 同频-背离） |
| M3 出刊引擎 | ✅ | p5 模板驱动出刊，产出深度研判报告 |
| M4 前端 | ✅ | Laravel 站（Signify 风格），读取内核产物渲染 |
| M5 订阅 | ✅ | auth + 兴趣标签（领域×地区）+ 我的情报 + 推送通道 |
| 部署 | ✅ | 一键部署脚本（Ubuntu/CentOS 双 OS） |

## 一键出刊

```bash
python pipeline/run.py --from 2026-10-01 --to 2026-10-06   # 全链路：采集→抽取→研判→出刊
python pipeline/run.py --days 7                             # 周报：最近 7 天
python pipeline/run.py --days 30 --reextract                # 复用已采 raw，重跑抽取→出刊
python pipeline/run.py --days 30 --reanalyze                # 复用台账，只重跑研判→出刊
python pipeline/run.py --days 30 --republish                # 只重跑出刊（补标签/改模板）
```

退出码：`0` 全成功 ｜ `1` 部分失败，或台账非空而研判为空（判 LLM 静默失败，跳过出刊）｜ `2` 参数/凭证错误。

产物落在 `pipeline/data/<起>-<止>/`：`ledger.json`（台账）、`analysis.json`（研判）、`report.md`（文件交付物）、`report.json`（元数据）、`run.log`。

**30 天实测**：全链路 18 分 27 秒，其中 P3 抽取约 14 分钟、P4 研判单次调用约 16 秒。P4 输入约 11 万字符（≈8 万 token）单次即可，无上下文硬墙。

## 部署

### 前置要求

- VPS：2 核 4G 起（日报约 4 分钟，周报约 10 分钟，月报约 18 分钟）
- 系统：Ubuntu 20.04+/22.04/24.04、CentOS Stream 8/9、RHEL 8/9
- 端口：80/443 开放，SSH 22 已配置
- 带宽：国内 VPS 需能访问 CNTV API（新闻联播源）和 people.com.cn

### 1. 配置

```bash
git clone https://github.com/Abinius/LanTai.git
cd LanTai
cp config.sh.example config.sh
vim config.sh
```

`config.sh` 需要填写：

| 变量 | 必填 | 说明 |
|---|---|---|
| `DOMAIN` | 是 | 域名或 IP，如 `example.com` 或 `1.2.3.4` |
| `DB_PASS` | 是 | 数据库密码（别用默认值） |
| `DB_ROOT_PASS` | 视情况 | MySQL root 密码；Ubuntu 默认 auth_socket 可留空 |
| `LLM_API_KEY` | 是 | sensenova API 密钥（`sk-` 开头） |
| `LLM_BASE_URL` | 否 | LLM 端点，留空用 sensenova 默认 |
| `LLM_MODEL` | 否 | LLM 模型名，留空用 sensenova-6.8-flash-lite |

### 2. 执行部署

```bash
sudo ./deploy.sh config.sh
```

脚本自动完成：装系统依赖 → 复制项目 → 配 .env → 建数据库 + 迁移 → 装 composer/npm 依赖 → 建前端资源 → 配 Nginx/PHP-FPM → 建 cron（日报+周报）→ 配防火墙。

不带参数直接 `sudo ./deploy.sh` 会交互式问配置。

### 3. 验证

```bash
# 站点是否通
curl -I http://localhost/

# 手工跑一次内核（首次出刊，约 4-10 分钟）
cd /var/www/lantai && /usr/bin/python3 pipeline/run.py --days 7

# 刷新页面看报告是否出现
```

### 4. 部署后常用命令

```bash
# 查看应用日志
tail -f /var/www/lantai/site/storage/logs/laravel.log

# 查看内核日志
tail -f /var/log/lantai-pipeline.log

# 查看定时任务
crontab -l

# 重启服务
systemctl restart nginx php*-fpm

# 更新代码（保留 .env 和数据库）
cd /var/www/lantai
git pull
cd site && composer install --no-dev && npm install && npm run build:css
```

### 5. 目录结构

```
/var/www/lantai/
├── site/              # Laravel 站点
│   └── public/        # Nginx root
├── pipeline/          # Python 内核
│   └── data/          # 出刊产物
└── .env               # Laravel 配置
/var/www/llm key.txt   # LLM 凭证（config.py 读取路径）
```

### 6. 注意事项

- **`LANTAI_DATA_DIR` 留空**：脚本生成的 .env 里此项为空，兜底路径 `../pipeline/data` 正好匹配部署布局
- **HTTPS**：脚本只配 HTTP，需自行装 certbot：`apt install certbot python3-certbot-nginx && certbot --nginx`
- **MySQL root 密码**：Ubuntu 默认 auth_socket（`mysql -uroot` 即可），CentOS MariaDB 默认 unix_socket（同理），如已设密码则填 `DB_ROOT_PASS`
- **Node.js**：脚本用 nodesource 22.x LTS 安装；如已有 Node 18+ 可跳过

## 起站（本地开发）

```bash
cd site && composer install && npm install && npm run build:css
cd site && php artisan serve --host=127.0.0.1 --port=8000
```

访问 `http://127.0.0.1:8000/`（情报流 + 全文检索 + 报告详情 + 订阅）。

## 测试

```bash
cd site && php vendor/bin/phpunit          # PHP 16 例
python -m pytest pipeline/tests -q          # Python 36 例
```

## 配置

| 变量 | 说明 | 默认 |
|---|---|---|
| `LANTAI_LLM_KEY` | LLM API 密钥（或写 `../llm key.txt`） | — |
| `LANTAI_LLM_BASE_URL` | LLM 端点 | sensenova |
| `LANTAI_LLM_MODEL` | LLM 模型 | sensenova-6.8-flash-lite |
| `LANTAI_BRAND` | 品牌名 | 兰台观局 |
| `LANTAI_SLOGAN` | 品牌语 | 兰台之上，静观天下局 |
| `LANTAI_OG_IMAGE` | 社交分享预览图 | android-chrome-512x512.png |
| `LANTAI_DATA_DIR` | 内核产物目录（留空默认 `../pipeline/data`） | — |
| `LANTAI_LEDGER_PREVIEW` | 报告详情页台账预览条数 | 60 |

## 台账可信度机制

- **可溯源**：每个数据点保留原文 URL 与原文切片，站端与报告均可逐项回原文核对。
- **时间红线**：`pub_date` 解析不出留空，禁止用抓取时间冒充事件真实时间。
- **地区维度**：`region` 分四类——`全国` / 省级裸名 / `地区`（境外或跨区域）/ `县域`（地方案例）。P4 研判按口径分组，**禁止用省级数据论证全国结论、禁止用县域数据作宏观依据**。
- **降级隔离**：LLM 调用失败时正则命中片段仍入台账但标 `llm_unverified`，P4 不得将其作为论据，前端与报告显式标记。

## 目录

```
.
├── docs/          # 产品与设计文档
├── pipeline/      # Python 内核：p1 区间 / p2 采集 / p3 抽取 / p4 研判 / p5 出刊
│   ├── prompts/   #   LLM 提示词
│   ├── templates/ #   报告骨架
│   ├── tests/     #   单元测试
│   └── data/      #   出刊产物（不入库）
├── site/          # Laravel 站点
├── deploy.sh      # 一键部署脚本
├── config.sh.example  # 部署配置模板
└── 开发日志.txt     # 开发实录
```

## 文档

| 文档 | 内容 |
|---|---|
| [政策情报工作站](docs/兰台观局%20·%20政策情报工作站.md) | PRD & 开发文档 v3.0 |
| [UI 设计规范](docs/兰台观局%20·%20UI%20设计规范（细化版）.md) | 前端设计基准 |
| [开发日志](开发日志.txt) | 开发实录：M1–M5、信源探测、踩雷、已知缺口 |

## 仓库

| 平台 | 地址 | 可见性 |
|---|---|---|
| GitHub | https://github.com/Abinius/LanTai | public |
| Gitee | https://gitee.com/abinink/LanTai | private |
