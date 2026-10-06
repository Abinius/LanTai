<?php

namespace App\Http\Controllers;

use App\Services\PublicationService;
use App\Services\SubscriptionService;

/**
 * 我的情报：按兴趣标签匹配到的期数。
 *
 * 拉取式浏览——站内暂无推送通道（无收件与推送任务），
 * 用户主动进来按标签看。推送落地后此控制器仍是入口。
 */
class BriefingController extends Controller
{
    public function __construct(
        private SubscriptionService $subscriptions,
        private PublicationService $publications,
    ) {
    }

    public function index(): \Illuminate\Contracts\View\View
    {
        $user = auth()->user();

        return view('briefing.index', [
            'briefings' => $this->subscriptions->briefingsFor($user, $this->publications->summaries()->all()),
            'tags' => $this->subscriptions->tagsFor($user),
        ]);
    }
}
