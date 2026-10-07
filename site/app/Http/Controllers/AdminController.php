<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\File;

/**
 * 情报后台（PRD §3.7 最小版）：信源状态 + 期数诊断。
 *
 * 只读，不写库；数据来自 pipeline/data/ 目录（run.log + JSON 产物）与
 * config/lantai.php 的品牌配置。信源注册在 pipeline/config.py 里硬编码，
 * 本页只做展示——不建信源增删表（v3.2 再考虑）。
 *
 * 权限：登录用户可访问（MVP 阶段不区分 admin 与普通用户）。
 */
class AdminController extends Controller
{
    /**
     * 信源状态：每个信源最近一次成功采集时间 + 各期数采集条数汇总。
     */
    public function sources(): \Illuminate\Contracts\View\View
    {
        $this->ensureAdmin();

        return view('admin.sources', [
            'sources' => $this->sourcesStatus(),
        ]);
    }

    /**
     * 期数诊断：每期数的采集耗时、台账点数、报告长度、核验条数。
     */
    public function diagnosis(): \Illuminate\Contracts\View\View
    {
        $this->ensureAdmin();

        return view('admin.diagnosis', [
            'periods' => $this->periodsDiagnosis(),
        ]);
    }

    /**
     * 后台白名单校验。当前用户不在 LANTAI_ADMIN_EMAILS 内则 403。
     *
     * 后台暴露内部路径与 LLM 调用量，而订阅端是公开注册的，不能只靠 auth 中间件。
     */
    private function ensureAdmin(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    /** 内核产物目录 */
    private function dataDir(): string
    {
        return str_replace('\\', '/', config('lantai.data_dir') ?: base_path('../pipeline/data'));
    }

    /**
     * 信源状态汇总：从 raw/{source}/*.json 目录统计各信源在各期数的采集条数与
     * 最近一次成功时间（按文件 mtime 取最新）。
     *
     * @return \Illuminate\Support\Collection<int, array{name:string, label:string,
     *          articles:int, periods:\Illuminate\Support\Collection, latest:string}>
     */
    private function sourcesStatus(): \Illuminate\Support\Collection
    {
        $dir = $this->dataDir();
        if (! is_dir($dir)) {
            return collect();
        }

        // 信源注册来自 config：xwlb（新闻联播）+ rmrb（人民日报）
        $sourceNames = ['xwlb', 'rmrb'];
        $sourceLabels = [
            'xwlb' => '新闻联播',
            'rmrb' => '人民日报',
        ];

        $out = [];
        foreach ($sourceNames as $name) {
            $total = 0;
            $latestMtime = null;

            foreach (glob("{$dir}/*/raw/{$name}/*.json") ?: [] as $fp) {
                $total++;
                $mtime = @filemtime($fp);
                if ($mtime && ($latestMtime === null || $mtime > $latestMtime)) {
                    $latestMtime = $mtime;
                }
            }

            // 按期数统计。注意目录层级是 data/<period>/raw/<source>：
            // 期数是 raw 的**祖父**目录名，不是父目录（父目录恒为 "raw"）。
            $rows = [];
            foreach (glob("{$dir}/*", GLOB_ONLYDIR) ?: [] as $periodDir) {
                $period = basename($periodDir);
                $srcDir = "{$periodDir}/raw/{$name}";
                if (! is_dir($srcDir)) {
                    continue;
                }
                $rows[] = [
                    'period' => $period,
                    'count' => count(glob("{$srcDir}/*.json") ?: []),
                ];
            }
            // 期数按起点降序
            usort($rows, fn ($a, $b) => strcmp($b['period'], $a['period']));

            $out[] = [
                'name' => $name,
                'label' => $sourceLabels[$name] ?? $name,
                'articles' => $total,
                'periods' => collect($rows),
                'latest' => $latestMtime ? date('Y-m-d H:i', $latestMtime) : '—',
            ];
        }

        return collect($out);
    }

    /**
     * 期数诊断：从各期数目录读 run.log + ledger.json + report.json + verify.json。
     *
     * @return array<int, array{period:string, raw:int, points:int, judgments:int,
     *                          predictions:int, report_chars:int, sources:int,
     *                          verify_hits:int, verify_terms:int, duration:float}>
     */
    private function periodsDiagnosis(): array
    {
        $dir = $this->dataDir();
        if (! is_dir($dir)) {
            return [];
        }

        $out = [];
        foreach (glob("{$dir}/*", GLOB_ONLYDIR) as $periodDir) {
            $period = basename($periodDir);
            if (! preg_match('/^\d{8}(-\d{8})?$/', $period)) {
                continue;
            }

            $rawCount = 0;
            foreach (glob("{$periodDir}/raw/*/*.json") as $fp) {
                $rawCount++;
            }

            $points = 0;
            $pointsFile = "{$periodDir}/ledger.json";
            if (File::exists($pointsFile)) {
                $data = json_decode(File::get($pointsFile), true) ?: [];
                $points = count($data['points'] ?? []);
            }

            $judgments = 0;
            $predictions = 0;
            $analysisFile = "{$periodDir}/analysis.json";
            if (File::exists($analysisFile)) {
                $analysis = json_decode(File::get($analysisFile), true) ?: [];
                $judgments = count($analysis['core_judgments'] ?? []);
                $predictions = count($analysis['predictions'] ?? []);
            }

            $reportChars = 0;
            $sources = 0;
            $reportFile = "{$periodDir}/report.md";
            if (File::exists($reportFile)) {
                $reportChars = mb_strlen(File::get($reportFile));
            }
            $reportJsonFile = "{$periodDir}/report.json";
            if (File::exists($reportJsonFile)) {
                $reportJson = json_decode(File::get($reportJsonFile), true) ?: [];
                $sources = (int) ($reportJson['source_count'] ?? 0);
            }

            $verifyHits = 0;
            $verifyTerms = 0;
            $verifyFile = "{$periodDir}/verify.json";
            if (File::exists($verifyFile)) {
                $verify = json_decode(File::get($verifyFile), true) ?: [];
                $verifyHits = count($verify['hits'] ?? []);
                $verifyTerms = count(array_unique(array_column($verify['hits'] ?? [], 'keyword')));
            }

            // 采集耗时：从 run.log 首行到最后一个 P5 完成时间
            $duration = $this->parseDuration("{$periodDir}/run.log");

            $out[] = [
                'period' => $period,
                'raw' => $rawCount,
                'points' => $points,
                'judgments' => $judgments,
                'predictions' => $predictions,
                'report_chars' => $reportChars,
                'sources' => $sources,
                'verify_hits' => $verifyHits,
                'verify_terms' => $verifyTerms,
                'duration' => $duration,
            ];
        }

        usort($out, fn ($a, $b) => strcmp($b['period'], $a['period']));
        return $out;
    }

    /**
     * 从 run.log 解析「完整跑一期」的耗时（秒）。
     *
     * run.log 是**追加写**的：同一期可能先后跑过全量、--reanalyze、--republish。
     * 取末段会得到 --republish 的几秒（它只跑 P5），这不是这一期的真实成本。
     * 故按「开跑 → 完成」切段，优先返回**最后一段真正跑过 P3**（日志含
     * 「[p3] 台账写入」）的耗时；一段都没有则退回最后一段；再不行返回 -1。
     *
     * 时间戳只有 HH:MM:SS，跨零点的段按 end < start 判定为次日补一天。
     */
    private function parseDuration(string $logPath): float
    {
        if (! File::exists($logPath)) {
            return -1.0;
        }

        $lines = preg_split('/\r?\n/', File::get($logPath)) ?: [];
        $segments = [];   // 每段：['start' => int, 'end' => int|null, 'has_p3' => bool]
        $current = null;

        foreach ($lines as $line) {
            if (preg_match('/^\[(\d{2}):(\d{2}):(\d{2})\] === 兰台观局 v[\d.]+ 开跑/', $line, $m)) {
                if ($current !== null) {
                    $segments[] = $current;
                }
                $current = ['start' => $this->secs($m[1], $m[2], $m[3]), 'end' => null, 'has_p3' => false];
                continue;
            }
            if ($current === null) {
                continue;
            }
            if (preg_match('/^\[(\d{2}):(\d{2}):(\d{2})\] === 完成/', $line, $m)) {
                $current['end'] = $this->secs($m[1], $m[2], $m[3]);
            } elseif (str_contains($line, '[p3] 台账写入')) {
                $current['has_p3'] = true;
            }
        }
        if ($current !== null) {
            $segments[] = $current;
        }

        // 优先取最后一段跑过 P3 的；否则最后一段有结束时间的
        $withP3 = array_values(array_filter($segments, fn ($s) => $s['has_p3'] && $s['end'] !== null));
        $complete = array_values(array_filter($segments, fn ($s) => $s['end'] !== null));
        $pick = $withP3 ? end($withP3) : ($complete ? end($complete) : null);
        if ($pick === null) {
            return -1.0;
        }

        $delta = $pick['end'] - $pick['start'];
        if ($delta < 0) {
            $delta += 86400;  // 跨零点
        }
        return (float) $delta;
    }

    /** HH:MM:SS → 当日秒数 */
    private function secs(string $h, string $m, string $s): int
    {
        return ((int) $h) * 3600 + ((int) $m) * 60 + (int) $s;
    }
}
