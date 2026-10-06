<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 订阅记录：兴趣标签（PRD §3.9，领域 × 地区）。每用户一条，upsert 更新。
 *
 * domains 复用内核抽取阶段的九类关键词分类（pipeline/modules/p3_extract.py
 * 的 CATEGORY_KEYWORDS），regions 复用台账的 region 字段取值。
 * 两个数组各自为空表示该维度不限。
 */
class Subscription extends Model
{
    protected $fillable = [
        'user_id',
        'domains',
        'regions',
    ];

    protected $casts = [
        'domains' => 'array',
        'regions' => 'array',
    ];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
