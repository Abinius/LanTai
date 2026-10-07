@extends('layouts.app')

@section('title', '情报后台 · 期数诊断 · ' . config('lantai.brand'))

@section('content')
<section class="max-w-6xl mx-auto px-6 pt-16 pb-10">
  <p class="label-caption text-accent mb-4">ADMIN · DIAGNOSIS</p>
  <h1 class="font-display text-display-lg font-black text-ink">期数诊断</h1>
  <p class="mt-4 text-ink-soft leading-relaxed">
    每个期数的采集条数、台账点数、研判产出、报告长度与核验命中。
    数据来自 <code class="text-sm text-muted">pipeline/data/&lt;period&gt;/</code> 的 JSON 产物与 run.log。
  </p>

  @if(empty($periods))
  <p class="mt-10 text-muted">未找到期数数据。</p>
  @else
  <div class="mt-10 overflow-x-auto">
    <table class="w-full text-sm">
      <thead>
        <tr class="border-b border-hairline text-left text-muted">
          <th class="py-2 pr-3 font-medium">期数</th>
          <th class="py-2 pr-3 font-medium text-right">耗时</th>
          <th class="py-2 pr-3 font-medium text-right">原始</th>
          <th class="py-2 pr-3 font-medium text-right">台账</th>
          <th class="py-2 pr-3 font-medium text-right">判断</th>
          <th class="py-2 pr-3 font-medium text-right">预测</th>
          <th class="py-2 pr-3 font-medium text-right">核验</th>
          <th class="py-2 pr-3 font-medium text-right">源</th>
          <th class="py-2 font-medium text-right">报告字数</th>
        </tr>
      </thead>
      <tbody>
        @foreach($periods as $p)
        <tr class="border-b border-hairline/50 hover:bg-surface/50">
          <td class="py-2.5 pr-3">
            <a href="{{ route('publications.show', ['period' => $p['period']]) }}"
               class="font-mono text-ink hover:text-accent">{{ $p['period'] }}</a>
          </td>
          <td class="py-2.5 pr-3 text-right text-muted">
            {{ $p['duration'] >= 0 ? number_format($p['duration'], 0) . 's' : '—' }}
          </td>
          <td class="py-2.5 pr-3 text-right">{{ $p['raw'] }}</td>
          <td class="py-2.5 pr-3 text-right">{{ $p['points'] }}</td>
          <td class="py-2.5 pr-3 text-right">{{ $p['judgments'] }}</td>
          <td class="py-2.5 pr-3 text-right">{{ $p['predictions'] }}</td>
          <td class="py-2.5 pr-3 text-right">
            @if($p['verify_hits'] > 0)
              <span class="text-ink">{{ $p['verify_hits'] }}</span>
              <span class="text-muted text-xs">/{{ $p['verify_terms'] }}词</span>
            @else
              <span class="text-muted">—</span>
            @endif
          </td>
          <td class="py-2.5 pr-3 text-right text-muted">{{ $p['sources'] }}</td>
          <td class="py-2.5 text-right text-muted">{{ number_format($p['report_chars']) }}</td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  <div class="mt-8 text-xs text-muted leading-relaxed">
    <p>· <strong class="text-ink-soft">耗时</strong>：parseDuration 从 run.log 首行「开跑」到末行「完成」的时间差（最近一次运行）。</p>
    <p>· <strong class="text-ink-soft">核验</strong>：verify.json 的命中数 / 派生关键词数（P2.5 回查发改委·文旅的结果）。</p>
    <p>· <strong class="text-ink-soft">台账</strong>：ledger.json 的数据点数；<strong class="text-ink-soft">原始</strong>：raw/ 下所有原文条数。</p>
  </div>
  @endif
</section>
@endsection
