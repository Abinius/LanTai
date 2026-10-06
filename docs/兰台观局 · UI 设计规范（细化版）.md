# 兰台观局 · UI 设计规范（细化版）

> 基准：`github.com/Abinius/Signify`（已扒取源码）
> 时间：2026-10-06
> 用途：兰台观局前端（Laravel + Blade + Alpine.js + Tailwind）设计基准
> 原则：**最大化复用 Signify 的令牌与组件**，只新增兰台观局特有组件

***

## 一、设计基因

Signify 的设计语言 = **编辑风（Editorial）· 克制 · 高留白 · 强层级**。

兰台观局的品牌气质（史官观局·克制·高级）与之**高度同构**，故直接沿用，无需另起炉灶。

**一句话**：像一本**排印讲究的刊物**，不像一个"信息网站"。

***

## 二、设计令牌（Design Tokens）

> 直接照搬 `tailwind.config.js` + `resources/css/app.css`，**一字不改**。

### 2.1 色板

| **令牌**            | **值**                 | **用途**           |
| ----------------- | --------------------- | ---------------- |
| `paper`           | `#FAFAF7`             | 页面底色（暖白）         |
| `surface`         | `#FFFFFF`             | 卡片/浮层            |
| `ink`             | `#1A1A18`             | 主文字（近黑）          |
| `ink.soft`        | `#4A4A45`             | 次级文字             |
| `muted`           | `#8A8A82`             | 弱化文字/说明          |
| `hairline`        | `rgba(26,26,24,0.10)` | 细分割线             |
| `hairline.strong` | `rgba(26,26,24,0.18)` | 强分割线             |
| `accent`          | `#B3392C`             | 点缀色（朱红，**克制使用**） |
| `status.success`  | `#2F6B4F`             | 成功               |
| `status.warning`  | `#A8791E`             | 警告               |
| `status.danger`   | `#B3392C`             | 危险（同 accent）     |

> **纪律**：accent 是"印章"不是"油漆"——只用于强调标签、悬停、选中。全页占比 \< 5%。

### 2.2 字体

| **令牌**         | **字栈**                                                             | **用途**             |
| -------------- | ------------------------------------------------------------------ | ------------------ |
| `font-display` | `"Playfair Display", Georgia, "Songti SC", SimSun, serif`          | **标题/大字号**（衬线，刊物感） |
| `font-sans`    | `"Inter", system-ui, "PingFang SC", "Microsoft YaHei", sans-serif` | 正文/UI              |

**加载策略**（照搬 `partials/font-loader`）：拉丁字体走 `fonts.googleapis.cn`（国内节点），失败自动降级谷歌；**中文用系统字体**（不加载）。

### 2.3 字号（Display 用 clamp 自适应）

| **令牌**            | **值**                      |
| ----------------- | -------------------------- |
| `text-display-xl` | `clamp(44px, 6vw, 72px)`   |
| `text-display-lg` | `clamp(32px, 4vw, 48px)`   |
| `text-display-md` | `clamp(24px, 2.6vw, 34px)` |

### 2.4 圆角 / 阴影

| **令牌**         | **值**                            | **说明**           |
| -------------- | -------------------------------- | ---------------- |
| `rounded-2px`  | `2px`                            | **几乎直角**——刊物感的关键 |
| `shadow.float` | `0 2px 16px rgba(26,26,24,0.08)` | 唯一浮层阴影           |

***

## 三、组件库（从 Signify 抽取）

### 3.1 基础原子类（`app.css` @layer components，可直接复用）

| **类**            | **定义**                                                                   | **用途**             |
| ---------------- | ------------------------------------------------------------------------ | ------------------ |
| `.hairline-b`    | `border-b border-hairline`                                               | 细分隔线               |
| `.btn-ink`       | `bg-ink text-paper px-6 py-3 text-sm tracking-wide hover:bg-ink-soft`    | 主按钮（黑底）            |
| `.btn-outline`   | `border border-hairline-strong px-6 py-3 hover:bg-ink hover:text-paper`  | 次按钮（描边）            |
| `.label-caption` | `text-xs uppercase tracking-[0.08em]`                                    | **标签/眉标**（全大写、宽字距） |
| `.input-line`    | `w-full bg-transparent border-b border-hairline py-2.5 focus:border-ink` | **下划线输入框**（刊物式）    |
| `.field-error`   | `text-accent text-xs mt-1`                                               | 表单错误               |

### 3.2 布局骨架（`layouts/app.blade.php`）

* **右上角固定悬浮汉堡按钮**（`fixed top-5 right-5`，毛玻璃 `backdrop-blur`）。
* **全屏菜单弹窗**（Alpine `x-show="menuOpen"` + `x-cloak`）。
* 菜单项结构：`font-display text-display-md font-bold`（大标题）+ `label-caption text-muted`（副说明）→ **刊物目录感**。
* `body` = `min-h-screen flex flex-col`。

### 3.3 卡片网格（`entrepreneurs/index`）

* 网格：`grid sm:grid-cols-2 lg:grid-cols-3 gap-x-8 gap-y-14`
* 卡片：`border border-hairline bg-surface`，正方封面 `aspect-square` + 图 `object-cover`，悬停 `scale-[1.02] transition-transform duration-500`。
* 无图占位：`bg-ink/5` + 首字 `font-display text-6xl text-ink/20`。

### 3.4 详情页版式（`entrepreneurs/show`）★可复用为"报告详情"

* 栏式：`grid md:grid-cols-5`（左 2 竖图 `aspect-[4/5]`，右 3 文字）。
* 标题：`font-display text-display-lg font-black`；眉标：`label-caption text-accent`。
* 底部社链区：`border-t border-hairline` + 一组线性 SVG 图标（统一 `stroke-width 1.8`）。
* 弹窗：Alpine `x-data="{ showQr: false }"` + `x-show`。

### 3.5 筛选栏（`entrepreneurs/index`）

* `border-b border-hairline pb-6 flex flex-col md:flex-row gap-5 md:items-end`
* 每项：`label-caption text-muted`（标签）+ `.input-line`（控件）
* 提交：`.btn-ink`

### 3.6 分页组件（`components/pagination`）

* 极简：`← 上一页` / `第 N / M 页` / `下一页 →`
* 禁用态：`text-muted opacity-50`；页码 `tabular-nums`。

### 3.7 分享卡（og 标签）

* `og:title/description/image/url` 可按页覆盖（`@yield` / `@section`）。
* 微信/社媒分享**开箱即用**。

### 3.8 交互与兼容（`app.css`）

* **Alpine 弹窗防闪**：`[x-cloak]{display:none!important}`
* **iOS 防缩放**：表单控件字号强制 ≥16px + `touch-action: manipulation`
* `::selection` = accent 底白字

***

## 四、页面映射（Signify → 兰台观局）

> **核心策略**：结构照搬，内容替换。

| **Signify 页**              | **兰台观局对应页** | **复用点**  | **改动**         |
| -------------------------- | ----------- | -------- | -------------- |
| `auth/login`（登录墙）          | **首页/登录墙**  | 版式全复用    | 文案换品牌语         |
| `entrepreneurs/index`（智库）  | **情报流**     | 网格+筛选+分页 | 卡片换成"报告卡"      |
| `entrepreneurs/show`（人物详情） | **报告详情**    | 5 栏版式    | 竖图→报告封面/无；正文加长 |
| `profile/edit`（个人中心）       | **订阅设置**    | 表单体系     | 字段换成兴趣标签       |
| `admin/*`（后台）              | **情报后台**    | 布局全复用    | 表换成信源/报告       |
| og 分享卡                     | **报告分享卡**   | 全复用      | 标题/描述          |

***

## 五、需新建的组件（兰台观局特有）

| **组件**              | **说明**            | **设计要点**                                              |
| ------------------- | ----------------- | ----------------------------------------------------- |
| **报告卡（ReportCard）** | 替代 Signify 的"人物卡" | 无封面 → 用**大标题 + 眉标日期**做主视觉，保持刊物感                       |
| **数据表（DataTable）**  | 报告内宏观数据表          | 无边框/仅 hairline 分隔；`tabular-nums`；表后"读法"               |
| **徽章（Badge）**       | 政策领域/层级           | `label-caption` + `border-hairline`；强调用 accent        |
| **趋势标记（TrendMark）** | ↑↓ 或 ★ 强度         | 单色为主，克制用色                                             |
| **正文排版（Prose）**     | 报告长文              | `font-display` 小标题 + `font-sans` 正文，`leading-relaxed` |
| **马克笔（Mark）**       | 品牌 slogan 位       | "兰台之上，静观天下局"                                          |

***

## 六、落地清单（文件级）

```
resources/
├── css/app.css                    # 复用 Signify（令牌+原子类）
├── views/
│   ├── layouts/app.blade.php      # 复用（全屏菜单骨架）
│   ├── components/
│   │   ├── pagination.blade.php   # 复用
│   │   ├── flash.blade.php        # 复用
│   │   ├── report-card.blade.php  # ★新建
│   │   ├── badge.blade.php        # ★新建
│   │   └── data-table.blade.php   # ★新建
│   ├── intelligence/index.blade.php  # 情报流（仿 entrepreneurs/index）
│   ├── intelligence/show.blade.php   # 报告详情（仿 show）
│   ├── auth/login.blade.php       # 复用
│   └── admin/                     # 复用
└── tailwind.config.js             # 复用（令牌）
```

**改造量**：约 **70% 复用 + 30% 新建**（新建集中在 3 个组件 + 2 个页面）。

***

## 七、设计纪律（写作要点）

1. **留白优先**——宁可少放，不要塞满。
2. **衬线管标题，无衬线管正文**——层级靠字体而非颜色。
3. **accent 是印章**——全页 \<5%，只点关键。
4. **直角（2px）+ 细线（hairline）**——刊物感的两根支柱。
5. **不用卡片阴影堆叠**——唯一浮层用 `shadow.float`。
6. **动效极轻**——只有 hover 变色与图片轻微缩放（200–500ms）。

***

> **结论**：兰台观局 UI = **Signify 设计系统直接继承**。品牌差异通过**内容、Logo、slogan、报告卡**体现，而非重造视觉。
> 这样做的好处：与 Signify 未来集成（订阅板块）**零视觉割裂**。
