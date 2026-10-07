<?php

namespace App\Console\Commands;

use App\Mail\ReportDigest;
use App\Services\PublicationService;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * lantai:push —— 把新期数推送到订阅用户邮箱。
 *
 * 用法：
 *   php artisan lantai:push                    按 freq 阈值推送（daily 24h / weekly 7d）
 *   php artisan lantai:push --dry-run          只打印要推什么，不发
 *   php artisan lantai:push --period=YYYYMMDD  强制推某一期，忽略时间窗口与订阅过滤
 *
 * Cron 建议：每日 9 点跑一次，命令内部按 freq 判断是否真的推。
 */
class PushSubscriptions extends Command
{
    protected $signature = 'lantai:push
        {--dry-run : 只打印要推什么，不实际发送邮件}
        {--period= : 强制推送指定期数，忽略时间窗口与订阅过滤（补推用）}';

    protected $description = '把新期数摘要推送到订阅用户邮箱';

    public function handle(PublicationService $publications, SubscriptionService $subscriptions): int
    {
        $now = Carbon::now();
        $dryRun = (bool) $this->option('dry-run');
        $forcedPeriod = $this->option('period');

        $summaries = $publications->summaries()->toArray();
        $totalReports = count($summaries);

        $this->info("扫描期数：{$totalReports} 期；时间：{$now->toDateTimeString()}");

        if ($forcedPeriod) {
            // 强制推送模式：向所有 channel=email 的用户推这一期，忽略时间窗口
            $targets = $this->resolveForced($forcedPeriod, $summaries);
        } else {
            // 常规推送：按 freq 阈值过滤用户，推最近一次推送时间之后的新期数
            $targets = $this->resolveRegular($now, $subscriptions, $summaries);
        }

        if (empty($targets)) {
            // 强制模式期数未找到 → 失败；常规模式无推送对象 → 成功
            if ($forcedPeriod) {
                return self::FAILURE;
            }
            $this->info('无需推送（用户为空或已达推送频率阈值）。');
            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;
        foreach ($targets as [$user, $reports]) {
            $reports = collect($reports);
            if ($reports->isEmpty()) {
                continue;
            }

            $email = $user->email;
            $periods = $reports->pluck('period')->implode(', ');

            if ($dryRun) {
                $this->line(sprintf(
                    '  [DRY] %s (%s) ← %s 期数：%s',
                    $user->name,
                    $email,
                    $reports->count(),
                    $periods,
                ));
                continue;
            }

            try {
                Mail::to($email)->send(new ReportDigest($user, $reports));
                $subscriptions->markPushed($user);
                $sent++;
                $this->line(sprintf(
                    '  ✓ %s (%s) ← %s 期数：%s',
                    $user->name,
                    $email,
                    $reports->count(),
                    $periods,
                ));
            } catch (\Throwable $e) {
                $failed++;
                $this->error(sprintf(
                    '  ✗ %s (%s) 失败：%s',
                    $user->name,
                    $email,
                    $e->getMessage(),
                ));
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '推送完成：%s%s%s',
            $sent,
            $sent === 0 && $failed === 0 ? ' 用户' : ' 成功',
            $failed > 0 ? "，{$failed} 失败" : '',
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 常规模式：只推 last_pushed_at 之后的新期数（首次推送推全部命中）。
     *
     * @return array<int, array{0: \App\Models\User, 1: array}>
     */
    private function resolveRegular(Carbon $now, SubscriptionService $subscriptions, array $summaries): array
    {
        $pushable = $subscriptions->pushableUsers($now);

        if ($pushable->isEmpty()) {
            return [];
        }

        $this->info("候选推送用户：{$pushable->count()}");

        return $pushable->map(function ($user) use ($subscriptions, $summaries) {
            $matched = $subscriptions->briefingsFor($user, $summaries);
            // 过滤到 last_pushed_at 之后的期数（首次推送无此字段，推全部命中）
            $lastPushed = $user->subscription?->last_pushed_at;
            if ($lastPushed) {
                $matched = array_values(array_filter($matched, function (array $item) use ($lastPushed) {
                    return strtotime($item['generated_at'] ?? '1970-01-01') > $lastPushed->timestamp;
                }));
            }
            return [$user, $matched];
        })->filter(fn (array $row) => ! empty($row[1]))->values()->all();
    }

    /**
     * 强制模式：向所有 channel=email 用户推指定期数，忽略时间窗口与订阅过滤。
     *
     * @return array<int, array{0: \App\Models\User, 1: array}>
     */
    private function resolveForced(string $period, array $summaries): array
    {
        $target = array_values(array_filter($summaries, fn (array $item) => $item['period'] === $period));
        if (empty($target)) {
            $this->error("未找到期数：{$period}");
            return [];
        }

        $this->warn("强制推送期数 {$period} 到所有 email 渠道订阅用户（忽略时间窗口与订阅过滤）");

        return \App\Models\Subscription::with('user')
            ->where('channel', 'email')
            ->get()
            ->map(fn ($sub) => [$sub->user, $target])
            ->all();
    }
}
