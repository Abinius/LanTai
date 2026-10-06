<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 我的情报：兴趣标签 ↔ 内核产物标签的匹配链路。
 *
 * 用一份假的 report.json/analysis.json/ledger.json 顶替内核产物，
 * 覆盖「产物标签 → 匹配 → 页面渲染」整条链路，不依赖真实 data/ 目录。
 */
class BriefingTest extends TestCase
{
    use RefreshDatabase;

    private string $dataDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dataDir = storage_path('testing/data');
        File::ensureDirectoryExists($this->dataDir);
        config(['lantai.data_dir' => $this->dataDir]);
        $this->publishPeriod('20260907-20260930', ['就业'], ['全国'], '乙期报告');
        $this->publishPeriod('20261001-20261005', ['投资', '消费'], ['全国', '山东'], '甲期报告');
        $this->publishPeriod('20261006', ['投资'], ['云南'], '丙期报告');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dataDir);

        parent::tearDown();
    }

    public function test_guest_cannot_open_briefing(): void
    {
        $this->get(route('briefing.index'))->assertRedirect(route('login'));
    }

    public function test_no_tags_shows_setup_prompt(): void
    {
        $user = User::create([
            'name' => 'reader',
            'email' => 'reader@example.com',
            'password' => 'secret-1',
        ]);

        $this->actingAs($user)
            ->get(route('briefing.index'))
            ->assertOk()
            ->assertSee('还没有兴趣标签')
            ->assertDontSee('甲期报告');
    }

    public function test_both_axes_must_match(): void
    {
        // 丙期有投资但没有山东，乙期两条轴都不命中
        $user = $this->reader(['投资'], ['山东']);

        $this->actingAs($user)
            ->get(route('briefing.index'))
            ->assertOk()
            ->assertSee('命中 1 期')
            ->assertSee('甲期报告')
            ->assertDontSee('乙期报告')
            ->assertDontSee('丙期报告');
    }

    public function test_unselected_axis_means_unlimited(): void
    {
        // 地区轴不选 → 不限，甲丙都命中
        $user = $this->reader(['投资'], []);

        $this->actingAs($user)
            ->get(route('briefing.index'))
            ->assertOk()
            ->assertSee('命中 2 期')
            ->assertSee('甲期报告')
            ->assertSee('丙期报告')
            ->assertDontSee('乙期报告');
    }

    public function test_no_matching_period_shows_hint(): void
    {
        $user = $this->reader(['财政'], ['江苏']);

        $this->actingAs($user)
            ->get(route('briefing.index'))
            ->assertOk()
            ->assertSee('还没有命中的期数');
    }

    public function test_service_reports_matched_tags(): void
    {
        $user = $this->reader(['投资'], ['山东']);

        $briefings = app(SubscriptionService::class)->briefingsFor(
            $user,
            app(\App\Services\PublicationService::class)->summaries()->all()
        );

        $this->assertCount(1, $briefings);
        $this->assertSame(['domains' => ['投资'], 'regions' => ['山东']], $briefings[0]['matched']);
        $this->assertSame(['投资', '消费'], $briefings[0]['domains']);
    }

    /** 写入一期假的内核产物（与内核产物同构） */
    private function publishPeriod(string $period, array $domains, array $regions, string $title): void
    {
        $dir = $this->dataDir.'/'.$period;
        File::ensureDirectoryExists($dir);

        File::put($dir.'/report.json', json_encode([
            'period' => $period,
            'title' => $title,
            'summary' => $title.' 摘要正文',
            'generated_at' => '2026-10-06T00:00:00+00:00',
            'source_count' => 2,
            'domains' => $domains,
            'regions' => $regions,
            'content_md' => '# '.$title,
        ], JSON_UNESCAPED_UNICODE));
        File::put($dir.'/analysis.json', json_encode([
            'period' => $period,
            'core_judgments' => ["{$title} 的核心判断"],
            'structural_findings' => [],
            'predictions' => [],
        ], JSON_UNESCAPED_UNICODE));
        File::put($dir.'/ledger.json', json_encode([
            'period' => $period,
            'points' => [],
        ], JSON_UNESCAPED_UNICODE));
    }

    private function reader(array $domains, array $regions): User
    {
        $user = User::create([
            'name' => 'reader',
            'email' => 'reader@example.com',
            'password' => 'secret-1',
        ]);

        app(SubscriptionService::class)->save($user, $domains, $regions);

        return $user;
    }
}
