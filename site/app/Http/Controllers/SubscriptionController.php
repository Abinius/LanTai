<?php

namespace App\Http\Controllers;

use App\Services\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 订阅设置：兴趣标签（领域 × 地区）。
 *
 * 标签候选来自 SubscriptionService 常量，与内核分类同源；
 * 提交时经 normalize() 过滤非法值，脏输入不会入库。
 */
class SubscriptionController extends Controller
{
    public function __construct(private SubscriptionService $subscriptions)
    {
    }

    /** 订阅设置页 */
    public function edit(): \Illuminate\Contracts\View\View
    {
        return view('subscription.edit', [
            'tags' => $this->subscriptions->tagsFor(auth()->user()),
            'domains' => SubscriptionService::DOMAINS,
            'regions' => SubscriptionService::REGIONS,
        ]);
    }

    /** 保存兴趣标签 */
    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'domains' => 'array',
            'domains.*' => 'string|max:20',
            'regions' => 'array',
            'regions.*' => 'string|max:20',
        ]);

        $this->subscriptions->save(
            auth()->user(),
            $request->domains ?? [],
            $request->regions ?? []
        );

        return back()->with('status', '兴趣标签已保存');
    }
}
