<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 订阅记录：兴趣标签（PRD §3.9，领域 × 地区）+ 推送设置。每用户一条，upsert 更新。
 *
 * domains 复用内核抽取阶段的九类关键词分类（pipeline/modules/p3_extract.py
 * 的 CATEGORY_KEYWORDS），regions 复用台账的 region 字段取值。
 * 两个数组各自为空表示该维度不限。
 *
 * channel/freq/last_pushed_at 是 W3 加的推送设置（PRD §3.9）：
 * - channel: 'none' | 'email'；none 表示不推，存量用户默认 none
 * - freq: 'daily' | 'weekly'；决定 lantai:push 命令的时间窗口
 * - last_pushed_at: 上次推送时间，幂等判断用（daily 24h、weekly 7d）
 */
class Subscription extends Model
{
    protected $fillable = [
        'user_id',
        'domains',
        'regions',
        'channel',
        'freq',
        'last_pushed_at',
    ];

    protected $casts = [
        'domains' => 'array',
        'regions' => 'array',
        'last_pushed_at' => 'datetime',
    ];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
