<?php

namespace App\Services;

use App\Models\User;

/**
 * 订阅匹配层：兴趣标签（领域 × 地区）↔ 期数标签。
 *
 * 领域候选与地区口径都复用内核的既有分类，不在站侧重造：
 * - DOMAINS 对应 pipeline/modules/p3_extract.py 的 CATEGORY_KEYWORDS 九类
 * - REGIONS 对应台账 DataPoint.region 的取值（全国 / 地区 / 县域 + 省级裸名）
 *
 * 匹配规则（AND 两条轴，轴内 OR）：
 * - 领域轴：订阅未选 → 不限；已选 → 期数领域与所选有交集
 * - 地区轴：订阅未选 → 不限；已选 → 期数地区与所选有交集
 * 注意选「全国」几乎会命中全部期数——绝大多数期数都含全国口径数据，
 * 这符合语义（全国数据普遍相关），不是 bug。
 */
class SubscriptionService
{
    public const DOMAINS = [
        '增长', '投资', '消费', '外贸', '物价', '金融', '能源', '财政', '就业',
    ];

    /** 三类口径 + 31 个内地省级行政区裸名（与台账 region 取值一致） */
    public const REGIONS = [
        '全国', '地区', '县域',
        '北京', '天津', '上海', '重庆',
        '河北', '山西', '辽宁', '吉林', '黑龙江',
        '江苏', '浙江', '安徽', '福建', '江西', '山东',
        '河南', '湖北', '湖南', '广东', '海南',
        '四川', '贵州', '云南', '陕西', '甘肃', '青海',
        '内蒙古', '广西', '西藏', '宁夏', '新疆',
    ];

    /** 当前生效的订阅标签；无订阅记录时两条轴都为空数组（即不限） */
    public function tagsFor(User $user): array
    {
        $sub = $user->subscription;

        return [
            'domains' => $sub?->domains ?? [],
            'regions' => $sub?->regions ?? [],
        ];
    }

    /** 保存/更新订阅标签（upsert，每用户一条） */
    public function save(User $user, array $domains, array $regions): void
    {
        $user->subscription()->updateOrCreate(
            [],
            [
                'domains' => $this->normalize($domains, self::DOMAINS),
                'regions' => $this->normalize($regions, self::REGIONS),
            ]
        );
    }

    /**
     * 按订阅过滤期数摘要列表。
     *
     * @param array $summaries PublicationService::summaries() 的数组形式
     * @return array 命中的摘要（保持原有顺序，按区间起点降序），
     *               每条附 `matched` 键标明命中的领域/地区标签
     */
    public function briefingsFor(User $user, array $summaries): array
    {
        $tags = $this->tagsFor($user);
        $hasDomain = ! empty($tags['domains']);
        $hasRegion = ! empty($tags['regions']);

        if (! $hasDomain && ! $hasRegion) {
            return [];
        }

        return array_values(array_filter(array_map(function (array $item) use ($tags, $hasDomain, $hasRegion) {
            // 轴内 OR：选中标签与期数标签有交集；未选该轴即不限
            $domains = $hasDomain
                ? array_values(array_intersect($tags['domains'], $item['domains'] ?? []))
                : [];
            $regions = $hasRegion
                ? array_values(array_intersect($tags['regions'], $item['regions'] ?? []))
                : [];

            // 轴间 AND：选了却没命中的轴直接淘汰
            if (($hasDomain && empty($domains)) || ($hasRegion && empty($regions))) {
                return null;
            }

            $item['matched'] = ['domains' => $domains, 'regions' => $regions];

            return $item;
        }, $summaries)));
    }

    /** 只保留候选表内的合法标签，去重保序，防脏输入 */
    private function normalize(array $values, array $allowed): array
    {
        return array_values(array_unique(
            array_filter($values, fn ($v) => is_string($v) && in_array($v, $allowed, true))
        ));
    }
}
