<?php

namespace App\Http\Controllers;

use App\Services\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 订阅设置：兴趣标签（领域 × 地区）+ 推送设置（渠道 × 频率）。
 *
 * 标签候选来自 SubscriptionService 常量，与内核分类同源；
 * 提交时经 normalize() 过滤非法值，脏输入不会入库。
 * 推送设置走独立白名单 CHANNELS/FREQS，不合法值回落默认（channel=none 即不推）。
 */
class SubscriptionController extends Controller
{
    public function __construct(private SubscriptionService $subscriptions)
    {
    }

    /** 订阅设置页 */
    public function edit(): \Illuminate\Contracts\View\View
    {
        $user = auth()->user();

        return view('subscription.edit', [
            'tags' => $this->subscriptions->tagsFor($user),
            'push' => $this->subscriptions->pushSettingsFor($user),
            'domains' => SubscriptionService::DOMAINS,
            'regions' => SubscriptionService::REGIONS,
            'channels' => SubscriptionService::CHANNELS,
            'freqs' => SubscriptionService::FREQS,
        ]);
    }

    /** 保存兴趣标签与推送设置 */
    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'domains' => 'array',
            'domains.*' => 'string|max:20',
            'regions' => 'array',
            'regions.*' => 'string|max:20',
            'channel' => 'nullable|string|max:20',
            'freq' => 'nullable|string|max:20',
        ]);

        $this->subscriptions->save(
            auth()->user(),
            $request->domains ?? [],
            $request->regions ?? [],
            $request->input('channel', 'none'),
            $request->input('freq', 'weekly'),
        );

        return back()->with('status', '订阅设置已保存');
    }
}
