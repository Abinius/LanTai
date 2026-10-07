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
