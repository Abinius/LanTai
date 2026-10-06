<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * 出刊读取层：只读内核产物 JSON，不入库。
 *
 * PRD §1：Laravel 站读取内核产出的报告/数据渲染。
 * 内核产物为可再生成的批处理输出，故不走 Eloquent——避免多一套同步链路。
 */
class PublicationService
{
    public function dataDir(): string
    {
        return str_replace('\\', '/', config('lantai.data_dir') ?: base_path('../pipeline/data'));
    }

    /**
     * 所有已出刊期数，按区间起点降序。
     */
    public function periods(): array
    {
        $dir = $this->dataDir();
        if (!File::isDirectory($dir)) {
            return [];
        }

        return collect(File::directories($dir))
            ->map(fn ($path) => basename($path))
            ->filter(fn ($period) => File::exists($this->reportFile($period)))
            ->values()
            ->sort(fn ($a, $b) => $b <=> $a)
            ->values()
            ->all();
    }

    /**
     * 情报流：报告摘要分页列表。
     * 搜索命中标题/摘要/研判/台账任一字段。
     */
    public function paginate(Request $request): LengthAwarePaginator
    {
        $q = trim((string) $request->query('q', ''));
        $items = $this->summaries();

        if ($q !== '') {
            $needle = mb_strtolower($q);
            $items = $items->filter(function (array $item) use ($needle) {
                return mb_strpos(mb_strtolower($item['searchable']), $needle) !== false;
            })->values();
        }

        $page = max(1, (int) $request->query('page', 1));

        return new LengthAwarePaginator(
            $items->slice(($page - 1) * config('lantai.per_page'))->values()->all(),
            $items->count(),
            config('lantai.per_page'),
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }

    /**
     * 单期完整数据：报告 + 研判 + 台账。缺失的文件降级为空结构。
     *
     * $period 直接拼进文件路径，只接受 YYYYMMDD 或 YYYYMMDD-YYYYMMDD，
     * 纵深防御路径穿越（Laravel SanitizePath 已拦一层）。
     *
     * 台账只返回前 config('lantai.ledger_preview') 条供页面渲染——月报台账
     * 可达数百条，全量渲染会让研判正文被表格淹没；完整台账留在内核产物。
     * 溯源清单与可见台账保持一致，避免列出页面看不到的数据点。
     */
    public function show(string $period): ?array
    {
        if (!self::isValidPeriod($period)) {
            return null;
        }

        $report = $this->readJson($this->reportFile($period));
        if ($report === null) {
            return null;
        }

        $base = $this->dataDir() . '/' . $period;

        $analysis = $this->readJson($base . '/analysis.json') ?? [
            'core_judgments' => [], 'structural_findings' => [], 'predictions' => [],
        ];
        $ledger = $this->readJson($base . '/ledger.json') ?? [
            'points' => [],
        ];

        $points = $ledger['points'] ?? [];
        $preview = array_slice($points, 0, (int) config('lantai.ledger_preview'));

        return [
            'report'      => $report,
            'analysis'    => $analysis,
            'ledger'      => $ledger,
            'points'      => $preview,
            'point_total' => count($points),
            'unverified'  => array_filter($points, fn ($p) => !empty($p['llm_unverified'])),
            'sources'     => $this->sources($preview, $analysis),
            'period'      => $period,
            'period_label' => self::periodLabel($period),
        ];
    }

    /** 去重的数据溯源清单：台账点在前，研判引用在后 */
    public function sources(array $points, array $analysis): array
    {
        $seen = [];

        foreach ($points as $p) {
            $url = $p['source_url'] ?? '';
            if ($url && !isset($seen[$url])) {
                $seen[$url] = $p['indicator'] ?? '';
            }
        }

        foreach ($analysis['predictions'] ?? [] as $pred) {
            foreach ($pred['data_refs'] ?? [] as $url) {
                if ($url && !isset($seen[$url])) {
                    $seen[$url] = '研判引用';
                }
            }
        }

        return $seen;
    }

    /**
     * 报告摘要（列表页用），含 searchable 供搜索拼接。
     */
    protected function summaries(): \Illuminate\Support\Collection
    {
        return collect($this->periods())->map(function (string $period) {
            $report = $this->readJson($this->reportFile($period)) ?? [];
            $analysis = $this->readJson($this->dataDir() . '/' . $period . '/analysis.json') ?? [];
            $ledger = $this->readJson($this->dataDir() . '/' . $period . '/ledger.json') ?? [];

            $points = $ledger['points'] ?? [];

            return [
                'period'    => $period,
                'title'     => $report['title'] ?? self::periodLabel($period),
                'summary'   => $report['summary'] ?? '',
                'generated_at' => $report['generated_at'] ?? '',
                'source_count' => (int) ($report['source_count'] ?? 0),
                'judgment_count' => count($analysis['core_judgments'] ?? []),
                'prediction_count' => count($analysis['predictions'] ?? []),
                'point_count' => count($points),
                'unverified_count' => count(array_filter($points, fn ($p) => !empty($p['llm_unverified']))),
                'searchable' => $this->searchable($report, $analysis, $points),
            ];
        });
    }

    /** 检索用全文串：标题/摘要/研判/台账文本拼平 */
    protected function searchable(array $report, array $analysis, array $points): string
    {
        return collect([
            $report['title'] ?? '',
            $report['summary'] ?? '',
            $analysis['core_judgments'] ?? [],
            $analysis['structural_findings'] ?? [],
            collect($points)->map(fn ($p) => trim(($p['indicator'] ?? '') . ' ' . ($p['raw_text'] ?? ''))),
        ])->flatten()->implode(' ');
    }

    protected function reportFile(string $period): string
    {
        return $this->dataDir() . '/' . $period . '/report.json';
    }

    protected function readJson(string $path): ?array
    {
        if (!File::exists($path)) {
            return null;
        }

        $decoded = json_decode(File::get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** 期数格式：YYYYMMDD 或 YYYYMMDD-YYYYMMDD */
    public static function isValidPeriod(string $period): bool
    {
        return (bool) preg_match('/^\d{8}(-\d{8})?$/', $period);
    }

    /** 区间标签：20261001-20261005 → 2026-10-01 ~ 2026-10-05 */
    public static function periodLabel(string $period): string
    {
        $fmt = fn ($p) => substr($p, 0, 4) . '-' . substr($p, 4, 2) . '-' . substr($p, 6, 2);

        if (str_contains($period, '-')) {
            [$a, $b] = explode('-', $period, 2);

            return $fmt($a) . ' ~ ' . $fmt($b);
        }

        return $fmt($period);
    }

    /** 出刊时间（UTC ISO）→ 本地可读日期 */
    public static function formatDate(?string $iso): string
    {
        if (!$iso) {
            return '—';
        }

        try {
            return Carbon::parse($iso)->toDateString();
        } catch (\Throwable) {
            return $iso;
        }
    }
}
