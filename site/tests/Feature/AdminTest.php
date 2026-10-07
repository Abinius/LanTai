<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 情报后台（PRD §3.7 最小版）：信源状态 + 期数诊断，仅白名单邮箱可访问。
 *
 * 白名单来自 config('lantai.admin_emails')（LANTAI_ADMIN_EMAILS）。
 * 校验重点：非白名单 403 / 未登录跳登录 / 白名单可读且渲染出内核产物数据。
 */
class AdminTest extends TestCase
{
    use RefreshDatabase;

    private string $dataDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataDir = storage_path('testing/admin_data');
        $this->fakeKernelProducts();
        config(['lantai.data_dir' => $this->dataDir]);
    }

    public function test_guest_redirected_to_login(): void
    {
        $this->get(route('admin.sources'))->assertRedirect(route('login'));
        $this->get(route('admin.diagnosis'))->assertRedirect(route('login'));
    }

    public function test_non_admin_gets_403(): void
    {
        config(['lantai.admin_emails' => ['boss@example.com']]);
        $user = $this->user('reader@example.com');

        $this->actingAs($user)->get(route('admin.sources'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.diagnosis'))->assertForbidden();
    }

    public function test_empty_whitelist_denies_everyone(): void
    {
        config(['lantai.admin_emails' => []]);
        $user = $this->user('reader@example.com');

        $this->actingAs($user)->get(route('admin.sources'))->assertForbidden();
    }

    public function test_admin_can_open_sources_page(): void
    {
        config(['lantai.admin_emails' => ['boss@example.com']]);
        $admin = $this->user('boss@example.com');

        $this->actingAs($admin)
            ->get(route('admin.sources'))
            ->assertOk()
            ->assertSee('信源状态')
            ->assertSee('新闻联播')
            ->assertSee('人民日报');
    }

    public function test_admin_can_open_diagnosis_page(): void
    {
        config(['lantai.admin_emails' => ['boss@example.com']]);
        $admin = $this->user('boss@example.com');

        $this->actingAs($admin)
            ->get(route('admin.diagnosis'))
            ->assertOk()
            ->assertSee('期数诊断')
            ->assertSee('20261001-20261005');
    }

    public function test_whitelist_is_case_insensitive(): void
    {
        config(['lantai.admin_emails' => ['Boss@Example.com']]);
        $admin = $this->user('boss@example.com');

        $this->actingAs($admin)->get(route('admin.sources'))->assertOk();
    }

    public function test_diagnosis_shows_kernel_counts(): void
    {
        config(['lantai.admin_emails' => ['boss@example.com']]);
        $admin = $this->user('boss@example.com');

        // 假产物：3 个 raw 条 + 2 个台账点 + 1 判断 + 1 预测 + 1 核验命中
        $this->actingAs($admin)
            ->get(route('admin.diagnosis'))
            ->assertOk()
            ->assertSee('20261001-20261005');
    }

    /**
     * 耗时必须是「完整跑一期」的耗时，不能被之后的 --republish/--reanalyze 覆盖。
     *
     * run.log 是追加写的：一期先全量跑（含 P3），之后补标签跑 --republish，
     * 末段只有几秒。早期实现取「最后一个开始 + 最后一个结束」，
     * 于是诊断页显示 5s —— 而这一期的真实成本是分钟级。
     */
    public function test_duration_reflects_full_run_not_republish(): void
    {
        config(['lantai.admin_emails' => ['boss@example.com']]);
        $admin = $this->user('boss@example.com');

        $base = "{$this->dataDir}/20261001-20261005";
        // 全量跑（含 P3 台账写入）：09:00:00 → 09:02:30
        // 之后 --republish（只有 P4/P5）：10:00:00 → 10:00:05
        file_put_contents("{$base}/run.log",
            "[09:00:00] === 兰台观局 v3.0 开跑 period=20261001-20261005 days=5 ===\n"
            . "[09:01:00] [p3] 台账写入 /data/ledger.json points=68\n"
            . "[09:02:30] === 完成 退出码=0 ===\n"
            . "[10:00:00] === 兰台观局 v3.0 开跑 period=20261001-20261005 days=5 ===\n"
            . "[10:00:00] --republish:跳过 P2/P3/P4,使用已有数据\n"
            . "[10:00:05] === 完成 退出码=0 ===\n"
        );

        $html = $this->actingAs($admin)->get(route('admin.diagnosis'))->assertOk()->getContent();

        $this->assertStringContainsString('150s', $html,
            '耗时应取含 P3 的全量跑（150s），而不是之后 --republish 的 5s');
        $this->assertStringNotContainsString('>5s<', $html);
    }

    /**
     * 信源状态页的「期数」列必须是期数本体，不能是路径片段。
     *
     * 曾用 basename(dirname($periodDir)) 取期数——$periodDir 形如
     * data/<period>/raw/<source>，dirname 去掉 source 后 basename 得到
     * 「raw」，于是每行期数都渲染成 "raw"，而真期数一个都不出现。
     */
    public function test_sources_page_lists_real_periods_not_path_segment(): void
    {
        config(['lantai.admin_emails' => ['boss@example.com']]);
        $admin = $this->user('boss@example.com');

        $html = $this->actingAs($admin)
            ->get(route('admin.sources'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('20261001-20261005', $html,
            '信源状态页应列出真实期数');

        // 「期数」列里不得出现路径片段 raw
        preg_match_all('/<td class="py-2\.5 font-mono">([^<]*)<\/td>/', $html, $m);
        $filled = array_values(array_filter(array_map('trim', $m[1] ?? [])));
        $this->assertNotEmpty($filled, '应渲染出期数行');
        $this->assertNotContains('raw', $filled, '期数列出现了路径片段 raw');
    }

    // ============ helpers ============

    private function user(string $email): User
    {
        return User::create([
            'name' => 'reader',
            'email' => $email,
            'password' => 'secret-1',
        ]);
    }

    /** 造一份最小内核产物目录，供后台读取 */
    private function fakeKernelProducts(): void
    {
        $base = "{$this->dataDir}/20261001-20261005";

        // raw：xwlb 2 条 + rmrb 1 条
        mkdir("{$base}/raw/xwlb", 0755, true);
        mkdir("{$base}/raw/rmrb", 0755, true);
        foreach (['a', 'b'] as $n) {
            file_put_contents("{$base}/raw/xwlb/20261001_{$n}.json", '{}');
        }
        file_put_contents("{$base}/raw/rmrb/20261001_a.json", '{}');

        file_put_contents("{$base}/ledger.json", json_encode([
            'period' => '20261001-20261005',
            'points' => [
                ['indicator' => 'i1', 'value' => '1'],
                ['indicator' => 'i2', 'value' => '2'],
            ],
        ], JSON_UNESCAPED_UNICODE));

        file_put_contents("{$base}/analysis.json", json_encode([
            'period' => '20261001-20261005',
            'core_judgments' => ['j1'],
            'structural_findings' => ['f1'],
            'predictions' => [['text' => 'p1', 'data_refs' => []]],
        ], JSON_UNESCAPED_UNICODE));

        file_put_contents("{$base}/report.json", json_encode([
            'period' => '20261001-20261005', 'title' => 't', 'summary' => 's',
            'kind' => 'brief', 'source_count' => 3, 'domains' => [], 'regions' => [],
            'content_md' => '# t',
        ], JSON_UNESCAPED_UNICODE));

        file_put_contents("{$base}/report.md", '# 标题' . str_repeat('字', 99));

        file_put_contents("{$base}/verify.json", json_encode([
            'hits' => [
                ['keyword' => 'GDP', 'title' => 'v1', 'url' => 'u1'],
            ],
            'count' => 1,
        ], JSON_UNESCAPED_UNICODE));

        file_put_contents("{$base}/run.log",
            "[09:00:00] === 兰台观局 v3.0 开跑 period=20261001-20261005 days=5 ===\n"
            . "[09:02:30] === 完成 退出码=0 ===\n"
        );
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dataDir);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $f) {
            $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
        }
        rmdir($dir);
    }
}
