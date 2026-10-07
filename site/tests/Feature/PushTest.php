<?php

namespace Tests\Feature;

use App\Mail\ReportDigest;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PublicationService;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * lantai:push 命令：邮件推送闭环。
 *
 * 校验重点：
 * - channel='email' 且达频率阈值才推
 * - daily 24h / weekly 7d 幂等
 * - 首次推送（last_pushed_at 为 null）推全部命中期数
 * - 已推送过的用户只推 last_pushed_at 之后的新期数
 * - --period 强制推某一期，忽略时间窗口
 * - --dry-run 不真发
 * - 单个用户失败不阻断其它用户
 */
class PushTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 用 log mailer 隔离外部依赖；不真发网络邮件
        config(['mail.default' => 'log']);
    }

    public function test_users_without_email_channel_are_not_pushed(): void
    {
        $user = $this->makeUser(['投资'], ['全国'], 'none', 'weekly');
        $this->fakeReports(['20261001-20261005']);

        Mail::fake();
        $this->artisan('lantai:push')->assertSuccessful();
        Mail::assertNothingSent();
    }

    public function test_first_time_subscriber_gets_push(): void
    {
        $user = $this->makeUser(['投资'], ['全国'], 'email', 'weekly');
        $this->fakeReports(['20261001-20261005']);

        Mail::fake();
        $this->artisan('lantai:push')->assertSuccessful();
        Mail::assertSent(ReportDigest::class, function ($mail) use ($user) {
            return $mail->recipient->email === $user->email;
        });
        // 推送成功后 last_pushed_at 被写入
        $this->assertNotNull($user->subscription->refresh()->last_pushed_at);
    }

    public function test_weekly_does_not_repush_within_7_days(): void
    {
        $user = $this->makeUser(['投资'], ['全国'], 'email', 'weekly');
        $user->subscription->update(['last_pushed_at' => Carbon::now()->subDays(2)]);
        $this->fakeReports(['20261001-20261005']);

        Mail::fake();
        $this->artisan('lantai:push')->assertSuccessful();
        Mail::assertNothingSent();
    }

    public function test_weekly_repushes_after_7_days(): void
    {
        $user = $this->makeUser(['投资'], ['全国'], 'email', 'weekly');
        $user->subscription->update(['last_pushed_at' => Carbon::now()->subDays(8)]);
        $this->fakeReports(['20261001-20261005']);

        Mail::fake();
        $this->artisan('lantai:push')->assertSuccessful();
        Mail::assertSent(ReportDigest::class, 1);
    }

    public function test_daily_does_not_repush_within_24_hours(): void
    {
        $user = $this->makeUser(['投资'], ['全国'], 'email', 'daily');
        $user->subscription->update(['last_pushed_at' => Carbon::now()->subHours(12)]);
        $this->fakeReports(['20261001-20261005']);

        Mail::fake();
        $this->artisan('lantai:push')->assertSuccessful();
        Mail::assertNothingSent();
    }

    public function test_daily_repushes_after_24_hours(): void
    {
        $user = $this->makeUser(['投资'], ['全国'], 'email', 'daily');
        $user->subscription->update(['last_pushed_at' => Carbon::now()->subHours(25)]);
        // 报告 generated_at 用 now()，确保晚于 last_pushed_at
        $this->fakeReports(['20261001-20261005'], ['投资'], ['全国'], Carbon::now()->subHour());

        Mail::fake();
        $this->artisan('lantai:push')->assertSuccessful();
        Mail::assertSent(ReportDigest::class, 1);
    }

    public function test_only_new_reports_after_last_push_are_sent(): void
    {
        $user = $this->makeUser(['投资'], ['全国'], 'email', 'daily');
        $lastPushed = Carbon::parse('2026-10-05T12:00:00+08:00');
        $user->subscription->update(['last_pushed_at' => $lastPushed]);

        // 三期：一期生成早于 lastPushed，两期之后
        $dir = storage_path('testing/data');
        mkdir($dir, 0755, true);
        $write = fn (string $period, Carbon $genAt) => file_put_contents(
            "{$dir}/{$period}/report.json",
            json_encode([
                'period' => $period, 'title' => "t {$period}", 'summary' => 's',
                'kind' => 'brief', 'generated_at' => $genAt->toIso8601String(),
                'source_count' => 1, 'domains' => ['投资'], 'regions' => ['全国'],
                'content_md' => '# t',
            ], JSON_UNESCAPED_UNICODE),
        );
        foreach ([
            ['20260901-20260905', Carbon::parse('2026-09-06T00:00:00+08:00')],
            ['20261001-20261005', Carbon::parse('2026-10-06T00:00:00+08:00')],
            ['20261006-20261007', Carbon::parse('2026-10-08T00:00:00+08:00')],
        ] as [$period, $genAt]) {
            mkdir("{$dir}/{$period}", 0755, true);
            $write($period, $genAt);
        }
        config(['lantai.data_dir' => $dir]);

        Mail::fake();
        $this->artisan('lantai:push')->assertSuccessful();

        Mail::assertSent(ReportDigest::class, 1, function ($mail) {
            $periods = $mail->reports->pluck('period')->toArray();
            return ! in_array('20260901-20260905', $periods)
                && in_array('20261001-20261005', $periods)
                && in_array('20261006-20261007', $periods);
        });
    }

    public function test_forced_period_ignores_time_window(): void
    {
        $user = $this->makeUser(['投资'], ['全国'], 'email', 'weekly');
        $user->subscription->update(['last_pushed_at' => Carbon::now()]);  // 刚推送过，weekly 阈值未达
        $this->fakeReports(['20261001-20261005']);

        Mail::fake();
        $this->artisan('lantai:push', ['--period' => '20261001-20261005'])->assertSuccessful();
        Mail::assertSent(ReportDigest::class, 1);
    }

    public function test_dry_run_does_not_send(): void
    {
        $user = $this->makeUser(['投资'], ['全国'], 'email', 'weekly');
        $this->fakeReports(['20261001-20261005']);

        Mail::fake();
        $this->artisan('lantai:push', ['--dry-run' => true])->assertSuccessful();
        Mail::assertNothingSent();
        // dry-run 不写 last_pushed_at
        $this->assertNull($user->subscription->refresh()->last_pushed_at);
    }

    public function test_unknown_period_errors_cleanly(): void
    {
        $this->fakeReports(['20261001-20261005']);

        Mail::fake();
        $this->artisan('lantai:push', ['--period' => '20991231-20991231'])
            ->assertFailed();
        Mail::assertNothingSent();
    }

    public function test_user_without_match_is_filtered(): void
    {
        // 订阅了「投资+山东」，但现有期数只有「消费+全国」——不匹配，不推
        $user = $this->makeUser(['投资'], ['山东'], 'email', 'weekly');
        $this->fakeReports(['20261001-20261005'], ['消费'], ['全国']);

        Mail::fake();
        $this->artisan('lantai:push')->assertSuccessful();
        Mail::assertNothingSent();
    }

    // ============ helpers ============

    private function makeUser(array $domains, array $regions, string $channel, string $freq): User
    {
        $user = User::create([
            'name' => 'reader',
            'email' => 'reader@example.com',
            'password' => 'secret-1',
        ]);
        app(SubscriptionService::class)->save($user, $domains, $regions, $channel, $freq);
        return $user;
    }

    /**
     * 用假的 report.json 覆盖内核产物目录，避免测试依赖真实 data/ 目录。
     * 覆盖到 storage/testing/data，tearDown 里手动清理。
     *
     * $generatedAt: 显式覆盖期数生成时间（默认 now()，确保晚于测试里设的 lastPushed）
     */
    private function fakeReports(
        array $periods,
        array $domains = ['投资'],
        array $regions = ['全国'],
        ?Carbon $generatedAt = null,
    ): void {
        $dir = storage_path('testing/data');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        foreach ($periods as $period) {
            $sub = "{$dir}/{$period}";
            mkdir($sub, 0755, true);
            file_put_contents("{$sub}/report.json", json_encode([
                'period' => $period,
                'title' => "测试报告 · {$period}",
                'summary' => '这是摘要',
                'kind' => 'brief',
                'generated_at' => ($generatedAt ?? Carbon::now())->toIso8601String(),
                'source_count' => 5,
                'domains' => $domains,
                'regions' => $regions,
                'content_md' => '# 测试报告',
            ], JSON_UNESCAPED_UNICODE));
        }
        config(['lantai.data_dir' => $dir]);
    }

    protected function tearDown(): void
    {
        // 清理假产物目录
        $dir = storage_path('testing/data');
        if (is_dir($dir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
            }
            rmdir($dir);
        }
        parent::tearDown();
    }
}
