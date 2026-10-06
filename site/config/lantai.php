<?php

/*
|--------------------------------------------------------------------------
| 兰台观局 · 品牌与内核产物配置
|--------------------------------------------------------------------------
| PRD §1 核心思路：Laravel 站读取内核（pipeline/）产出的报告/数据渲染。
| 内核产物目录结构：
|   data/<period>/report.md        深度报告（文件交付物）
|   data/<period>/report.json      报告元数据
|   data/<period>/analysis.json    三维交叉研判（P4）
|   data/<period>/ledger.json      数据台账（P3）
|   data/<period>/raw/{xwlb,rmrb}  原始采集（P2）
*/

return [
    'brand'      => env('LANTAI_BRAND', '兰台观局'),
    'distributor' => env('LANTAI_DISTRIBUTOR', '松山高参'),
    'slogan'     => env('LANTAI_SLOGAN', '兰台之上，静观天下局'),
    'footer_note' => env('LANTAI_FOOTER', '兰台观局 出品 · 松山高参 分发'),

    /* 内核产物目录。留空默认 ../pipeline/data（与 site/ 同级的 pipeline/） */
    'data_dir'   => env('LANTAI_DATA_DIR'),

    /* 情报流每页篇数 */
    'per_page'   => (int) env('LANTAI_PER_PAGE', 12),

    /* 报告详情页台账预览条数；完整台账留在内核产物，不在页面全量渲染 */
    'ledger_preview' => (int) env('LANTAI_LEDGER_PREVIEW', 60),
];
