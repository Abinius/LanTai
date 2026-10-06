# 兰台观局

以国字号舆论场与官方宏观数据为信源，经「采集 → 抽取 → 三维交叉研判 → 出刊」五阶段流水线，产出**少而准**的政策判断。

- **出品**：兰台观局 ｜ **分发**：松山高参 ｜ **品牌语**：兰台之上，静观天下局
- **内核**：Python 批处理（月度监测五阶段，标准库为主）
- **站点**：Laravel 11 + Blade + Alpine.js + Tailwind（Signify 风格）
- **数据**：MySQL
- **部署**：零 Docker（Nginx + PHP-FPM + MySQL + cron + systemd）

## 文档

| 文档 | 内容 |
|---|---|
| [政策情报工作站](docs/兰台观局%20·%20政策情报工作站.md) | PRD & 开发文档 v3.0，总纲：定位、五阶段流水线、出刊引擎、技术选型 |
| [UI 设计规范（细化版）](docs/兰台观局%20·%20UI%20设计规范（细化版）.md) | 前端设计基准：设计令牌、组件库、页面映射 |

## 当前状态

**骨架未开始。** 上一代实现（AIHOT 引擎定制版）已于 2026-10-06 卸载，
经验教训与决策记录见上级目录的 `开发日志.txt`，备份在上级目录的 `backup/`。

## 目录约定

```
.
├── docs/          # 产品与设计文档（本仓库的规格来源）
├── pipeline/      # Python 内核：interval / collect / extract / analyze / publish
├── site/          # Laravel 站点（Signify 风格 UI）
├── templates/     # 出刊模板（report-template.md 等）
└── data/          # 本地数据（不入库）
```
