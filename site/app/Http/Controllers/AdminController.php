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

            // 按期数统计
            $rows = [];
            foreach (glob("{$dir}/*/raw/{$name}", GLOB_ONLYDIR) ?: [] as $periodDir) {
                $rows[] = [
                    'period' => basename(dirname($periodDir)),
                    'count' => count(glob("{$periodDir}/*.json") ?: []),
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
     * 从 run.log 解析单次运行的耗时（秒）。
     *
     * 找 "=== 兰台观局 v3.0 开跑" 与紧随其后的 "=== 完成" 两次时间戳，
     * 差值即最近一次运行的耗时。多段运行取最后一段。
     */
    private function parseDuration(string $logPath): float
    {
        if (! File::exists($logPath)) {
            return -1.0;
        }
        $content = File::get($logPath);
        $startPattern = '/^\[(\d{2}):(\d{2}):(\d{2})\] === 兰台观局 v[\d.]+ 开跑/';
        $endPattern = '/^\[(\d{2}):(\d{2}):(\d{2})\] === 完成/';

        $lines = preg_split('/\n/', $content) ?: [];
        $lastStart = null;
        $lastEnd = null;
        $lastStartTs = null;
        $lastEndTs = null;
        $day = date('Y-m-d');

        foreach ($lines as $line) {
            if (preg_match($startPattern, $line, $m)) {
                $lastStart = [$m[1], $m[2], $m[3]];
                $lastStartTs = $this->makeTs($day, $m[1], $m[2], $m[3]);
            } elseif (preg_match($endPattern, $line, $m)) {
                $lastEnd = [$m[1], $m[2], $m[3]];
                $lastEndTs = $this->makeTs($day, $m[1], $m[2], $m[3]);
            }
        }

        if ($lastStartTs === null || $lastEndTs === null) {
            return -1.0;
        }
        return $lastEndTs - $lastStartTs;
    }

    private function makeTs(string $day, string $h, string $m, string $s): int
    {
        return strtotime("{$day} {$h}:{$m}:{$s}");
    }
}
