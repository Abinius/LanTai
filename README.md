# 兰台观局

以国字号舆论场与官方宏观数据为信源，经「采集 → 抽取 → 三维交叉研判 → 出刊」五阶段流水线，产出**少而准**的政策判断。

- **出品**：兰台观局 ｜ **分发**：松山高参 ｜ **品牌语**：兰台之上，静观天下局
- **内核**：Python 批处理（五阶段，标准库为主，采集零依赖）
- **站点**：Laravel 11 + Blade + Alpine.js + Tailwind（Signify 风格）
- **数据**：产物 JSON 只读，不入站库
- **部署**：零 Docker（Nginx + PHP-FPM + MySQL + cron + systemd）

## 当前状态

**M1–M4 已交付，M5 前置三项已落地（2026-10-06）。**

| 里程碑 | 状态 | 内容 |
|---|---|---|
| M1 内核 MVP | ✅ | p1 区间 + p2 双适配器（新闻联播/人民日报）+ p3 抽取 → 台账 |
| M2 研判 | ✅ | p4 三维交叉研判（数据 × 官媒 × 同频-背离） |
| M3 出刊引擎 | ✅ | p5 模板驱动出刊，产出深度研判报告 |
| M4 前端 | ✅ | Laravel 站（Signify 风格），读取内核产物渲染 |
| M5 前置 | ✅ | 30 天真实压测 + 台账地区维度 + 详情页表格分层 + 降级点隔离 |
| M5 集成 | ⏳ | Signify 订阅板块（兴趣标签 + 摘要推送） |

## 一键出刊

```bash
python pipeline/run.py --from 2026-10-01 --to 2026-10-06   # 全链路：采集→抽取→研判→出刊
python pipeline/run.py --days 30                            # 月报：最近 30 天
python pipeline/run.py --days 30 --reextract                # 复用已采 raw，重跑抽取→出刊
python pipeline/run.py --days 30 --reanalyze                # 复用台账，只重跑研判→出刊
```

退出码：`0` 全成功 ｜ `1` 部分失败，或台账非空而研判为空（判 LLM 静默失败，跳过出刊）｜ `2` 参数/凭证错误。

产物落在 `pipeline/data/<起>-<止>/`：`ledger.json`（台账）、`analysis.json`（研判）、`report.md`（文件交付物）、`report.json`（元数据）、`run.log`。

**月度实测（30 天）**：全链路 18 分 27 秒，其中 P3 抽取约 14 分钟、P4 研判单次调用约 16 秒。P4 输入约 11 万字符（≈8 万 token）单次即可，无上下文硬墙。

## 起站

```bash
cd site && composer install && npm install && npm run build:css
cd site && php artisan serve --host=127.0.0.1 --port=8000
```

访问 `http://127.0.0.1:8000/`（情报流 + 全文检索 + 报告详情）。

改 `LANTAI_DATA_DIR` 后记得 `php artisan config:clear`。**VPS 上该项应留空**，兜底路径 `base_path('../pipeline/data')` 正好匹配 `/www/laotai` + `/www/laotai-kernel` 的部署布局。

## 台账可信度机制

这是产品的可信度基础，改动需谨慎：

- **可溯源**：每个数据点保留原文 URL 与原文切片，站端与报告均可逐项回原文核对。
- **时间红线**：`pub_date` 解析不出留空，禁止用抓取时间冒充事件真实时间。
- **地区维度**：`region` 分四类——`全国` / 省级裸名 / `地区`（境外或跨区域）/ `县域`（地方案例）。P4 研判按口径分组，**禁止用省级数据论证全国结论、禁止用县域数据作宏观依据**。
- **降级隔离**：LLM 调用失败时正则命中片段仍入台账但标 `llm_unverified`，P4 不得将其作为论据，前端与报告显式标记。

## 目录约定

```
.
├── docs/          # 产品与设计文档（本仓库的规格来源）
├── pipeline/      # Python 内核：p1 区间 / p2 采集 / p3 抽取 / p4 研判 / p5 出刊
│   ├── prompts/   #   LLM 提示词（抽取 / 研判 / 出刊）
│   ├── templates/ #   报告骨架
│   ├── tests/     #   单元测试
│   └── data/      #   出刊产物（可再生成，不入库）
├── site/          # Laravel 站点（Signify 风格 UI，读取 pipeline/data 渲染）
└── 开发日志.txt     # 开发实录：M1–M5 前置、信源探测、踩雷、已知缺口
```

## 文档

| 文档 | 内容 |
|---|---|
| [政策情报工作站](docs/兰台观局%20·%20政策情报工作站.md) | PRD & 开发文档 v3.0：定位、五阶段流水线、出刊引擎、技术选型 |
| [UI 设计规范（细化版）](docs/兰台观局%20·%20UI%20设计规范（细化版）.md) | 前端设计基准：设计令牌、组件库、页面映射 |
| [开发日志](开发日志.txt) | 开发实录：M1–M5 前置全过程、信源探测、踩雷、已知缺口 |
| [站点 README](site/README.md) | 站点结构、配置项、页面映射 |

> 上级目录 `../开发日志.txt` 是**上一代 AIHOT 引擎**的历史存档（含明文密钥，故意留在仓库外），
> 不属本项目。其中仍有效的踩雷已摘入本仓库日志第 7 节。

## 凭据与环境

- **LLM**：sensenova OpenAI 兼容接口。凭证读仓库**上一级**目录的 `llm key.txt`，或环境变量 `LANTAI_LLM_KEY` 覆盖。已 gitignore，切勿入库。
- **环境变量前缀**：`LANTAI_*`（站点配置见 `site/config/lantai.php`）。

## 仓库

| 平台 | 地址 | 可见性 |
|---|---|---|
| GitHub | https://github.com/Abinius/LanTai | public |
| Gitee | https://gitee.com/abinink/LanTai | private |

本地 remote：`origin` = GitHub，`gitee` = Gitee。
