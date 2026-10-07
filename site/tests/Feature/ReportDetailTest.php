<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 报告详情页：官方核验参考区块的渲染。
 *
 * 内核 P2.5 把核验命中写进 verify.json，此前站点详情页只从
 * analysis/ledger/report 三个 JSON 取数，从不读 verify.json——
 * 报告 md 里有「附:官方核验参考」，站点上却一个字都没有，
 * 等于这个功能对站点用户不可见。
 */
class ReportDetailTest extends TestCase
{
    use RefreshDatabase;

    private string $dataDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataDir = storage_path('testing/report_detail');
        File::ensureDirectoryExists($this->dataDir);
        config(['lantai.data_dir' => $this->dataDir]);
        $this->publishPeriod('20261001-20261005');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dataDir);
        parent::tearDown();
    }

    public function test_verify_section_rendered_when_hits_exist(): void
    {
        $this->writeVerify([
            ['keyword' => '固定资产投资', 'title' => '节能审查办法', 'url' => 'https://ndrc.gov.cn/a',
             'source' => 'ndrc', 'date' => '20250725', 'agency' => '国家发展改革委'],
        ]);

        $html = $this->get(route('publications.show', ['period' => '20261001-20261005']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('官方核验参考', $html);
        $this->assertStringContainsString('节能审查办法', $html);
        $this->assertStringContainsString('https://ndrc.gov.cn/a', $html);
        $this->assertStringContainsString('国家发展改革委', $html);
        $this->assertStringContainsString('固定资产投资', $html, '应按关键词分组');
    }

    public function test_verify_section_absent_without_hits(): void
    {
        // 没有 verify.json（老期数）
        $this->get(route('publications.show', ['period' => '20261001-20261005']))
            ->assertOk()
            ->assertDontSee('官方核验参考');
    }

    public function test_verify_section_absent_when_empty_hits(): void
    {
        $this->writeVerify([]);

        $this->get(route('publications.show', ['period' => '20261001-20261005']))
            ->assertOk()
            ->assertDontSee('官方核验参考');
    }

    public function test_corrupt_verify_json_does_not_break_page(): void
    {
        File::put($this->dataDir.'/20261001-20261005/verify.json', '{bad json');

        $this->get(route('publications.show', ['period' => '20261001-20261005']))
            ->assertOk()
            ->assertDontSee('官方核验参考');
    }

    public function test_hit_without_url_is_skipped(): void
    {
        $this->writeVerify([
            ['keyword' => 'GDP', 'title' => '无链接条', 'date' => '', 'agency' => ''],
            ['keyword' => 'GDP', 'title' => '有链接条', 'url' => 'https://x.gov.cn/b', 'date' => '', 'agency' => ''],
        ]);

        $this->get(route('publications.show', ['period' => '20261001-20261005']))
            ->assertOk()
            ->assertDontSee('无链接条')
            ->assertSee('有链接条');
    }

    // ============ helpers ============

    private function writeVerify(array $hits): void
    {
        File::put(
            $this->dataDir.'/20261001-20261005/verify.json',
            json_encode(['hits' => $hits, 'count' => count($hits)], JSON_UNESCAPED_UNICODE)
        );
    }

    private function publishPeriod(string $period): void
    {
        $dir = $this->dataDir.'/'.$period;
        File::ensureDirectoryExists($dir);

        File::put($dir.'/report.json', json_encode([
            'period' => $period, 'title' => '测试报告', 'summary' => '摘要',
            'kind' => 'brief', 'generated_at' => '2026-10-06T00:00:00+00:00',
            'source_count' => 1, 'domains' => [], 'regions' => [], 'content_md' => '# 测试报告',
        ], JSON_UNESCAPED_UNICODE));
        File::put($dir.'/analysis.json', json_encode([
            'period' => $period, 'core_judgments' => ['判断一'],
            'structural_findings' => [], 'predictions' => [],
        ], JSON_UNESCAPED_UNICODE));
        File::put($dir.'/ledger.json', json_encode([
            'period' => $period, 'points' => [],
        ], JSON_UNESCAPED_UNICODE));
    }
}
