@extends('layouts.app')

@section('title', '情报后台 · 信源状态 · ' . config('lantai.brand'))

@section('content')
<section class="max-w-5xl mx-auto px-6 pt-16 pb-10">
  <p class="label-caption text-accent mb-4">ADMIN · SOURCES</p>
  <h1 class="font-display text-display-lg font-black text-ink">信源状态</h1>
  <p class="mt-4 text-ink-soft leading-relaxed">
    各信源最近一次成功采集时间与该信源在不同期数的采集条数汇总。
    信源注册在 <code class="text-sm text-muted">pipeline/config.py</code> 硬编码，本页只做展示。
  </p>

  @if($sources->isEmpty())
  <p class="mt-10 text-muted">未找到信源数据（<code class="text-sm">LANTAI_DATA_DIR</code> 可能未指向有效目录）。</p>
  @endif
</section>

@foreach($sources as $source)
<section class="max-w-5xl mx-auto px-6 pb-14">
  <div class="flex items-baseline justify-between gap-4 border-b border-hairline pb-4">
    <h2 class="font-display text-display-md font-bold text-ink">
      {{ $source['label'] }}
      <span class="text-sm text-muted font-normal">({{ $source['name'] }})</span>
    </h2>
    <span class="label-caption text-muted">最近采集 · {{ $source['latest'] }}</span>
  </div>

  <p class="mt-4 text-sm text-ink-soft">
    累计采集 <span class="font-semibold text-ink">{{ $source['articles'] }}</span> 条 ·
    覆盖 <span class="font-semibold text-ink">{{ $source['periods']->count() }}</span> 个期数
  </p>

  @if($source['periods']->isEmpty())
  <p class="mt-4 text-sm text-muted">尚未采集。</p>
  @else
  <table class="mt-6 w-full text-sm">
    <thead>
      <tr class="border-b border-hairline text-left text-muted">
        <th class="py-2 font-medium">期数</th>
        <th class="py-2 font-medium text-right">采集条数</th>
      </tr>
    </thead>
    <tbody>
      @foreach($source['periods'] as $row)
      <tr class="border-b border-hairline/50">
        <td class="py-2.5 font-mono">{{ $row['period'] }}</td>
        <td class="py-2.5 text-right">{{ $row['count'] }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>
  @endif
</section>
@endforeach
@endsection
