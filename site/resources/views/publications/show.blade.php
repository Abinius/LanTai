@extends('layouts.app')

@section('title', $report['title'] ?? $period_label)

@section('og-title', $report['title'] ?? $period_label)
@section('og-description', $report['summary'] ?? '')
@section('og-url', url(route('publications.show', $period)))

@section('content')

<article>

  {{-- 眉栏：期数 + 出刊信息 --}}
  <header class="max-w-3xl mx-auto px-6 pt-16 pb-8">
    <div class="flex items-center justify-between gap-4 pb-5 border-b border-hairline">
      <p class="label-caption text-accent">{{ config('lantai.brand') }} · 深度研判</p>
      <p class="label-caption text-muted tabular-nums flex-shrink-0">
        {{ count($sources) }} 个数据源
      </p>
    </div>

    <h1 class="font-display text-display-lg font-black text-ink leading-tight mt-10">{{ $report['title'] ?? $period_label }}</h1>

    <div class="mt-6 flex flex-wrap items-center gap-x-4 gap-y-2">
      <x-badge>{{ $period_label }}</x-badge>
      <x-badge :emphasized="true">{{ count($analysis['predictions'] ?? []) }} 条趋势预测</x-badge>
      @if(count($unverified) > 0)
        <x-badge>{{ count($unverified) }} 点待核验</x-badge>
      @endif
      <span class="label-caption text-muted tabular-nums">出刊 {{ \App\Services\PublicationService::formatDate($report['generated_at'] ?? null) }}</span>
    </div>
  </header>

  {{-- 核心摘要 --}}
  @if(!empty($report['summary']))
    <section class="max-w-3xl mx-auto px-6 pb-12">
      <div class="border-l-2 border-accent pl-6">
        <p class="text-base leading-loose text-ink">{{ $report['summary'] }}</p>
      </div>
    </section>
  @endif

  {{-- 一、核心矛盾判断 --}}
  @if(count($analysis['core_judgments'] ?? []) > 0)
    <section class="max-w-3xl mx-auto px-6 pb-12">
      <div class="hairline-b pb-3 mb-7 flex items-baseline gap-3">
        <span class="label-caption text-muted">一</span>
        <h2 class="font-display text-display-md font-bold text-ink">核心矛盾判断</h2>
      </div>
      <ol class="space-y-6">
        @foreach($analysis['core_judgments'] as $judgment)
          <li class="flex gap-5">
            <span class="font-display text-2xl font-bold text-accent leading-none tabular-nums flex-shrink-0">{{ $loop->iteration }}</span>
            <p class="text-base leading-loose text-ink">{{ $judgment }}</p>
          </li>
        @endforeach
      </ol>
    </section>
  @endif

  {{-- 二、结构性发现 --}}
  @if(count($analysis['structural_findings'] ?? []) > 0)
    <section class="max-w-3xl mx-auto px-6 pb-12">
      <div class="hairline-b pb-3 mb-7 flex items-baseline gap-3">
        <span class="label-caption text-muted">二</span>
        <h2 class="font-display text-display-md font-bold text-ink">结构性发现</h2>
      </div>
      <ol class="space-y-5">
        @foreach($analysis['structural_findings'] as $finding)
          <li class="flex gap-5">
            <span class="font-display text-xl font-bold text-ink-soft leading-none tabular-nums flex-shrink-0">{{ $loop->iteration }}</span>
            <p class="text-sm leading-loose text-ink-soft">{{ $finding }}</p>
          </li>
        @endforeach
      </ol>
    </section>
  @endif

  {{-- 三、趋势预测（每条带数据支撑） --}}
  @if(count($analysis['predictions'] ?? []) > 0)
    <section class="max-w-3xl mx-auto px-6 pb-12">
      <div class="hairline-b pb-3 mb-7 flex items-baseline gap-3">
        <span class="label-caption text-muted">三</span>
        <h2 class="font-display text-display-md font-bold text-ink">趋势预测</h2>
        <span class="label-caption text-muted ml-auto">带数据支撑</span>
      </div>
      <ol class="space-y-8">
        @foreach($analysis['predictions'] as $pred)
          <li class="flex gap-5">
            <span class="font-display text-2xl font-bold text-accent leading-none tabular-nums flex-shrink-0">{{ $loop->iteration }}</span>
            <div class="min-w-0">
              <p class="text-base leading-loose text-ink">{{ $pred['text'] }}</p>
              @if(!empty($pred['data_refs']))
                <p class="mt-3">
                  <span class="label-caption text-muted mr-2">溯源</span>
                  @foreach($pred['data_refs'] as $i => $ref)
                    {{ $i > 0 ? '·' : '' }}
                    <a href="{{ $ref }}" target="_blank" rel="noopener noreferrer"
                       class="label-caption text-accent hover:underline whitespace-nowrap">原文 {{ $i + 1 }}↗</a>
                  @endforeach
                </p>
              @endif
            </div>
          </li>
        @endforeach
      </ol>
    </section>
  @endif

  {{-- 附：数据台账 --}}
  <section class="max-w-5xl mx-auto px-6 pb-12">
    <div class="hairline-b pb-3 mb-7 flex items-baseline gap-3">
      <span class="label-caption text-muted">附</span>
      <h2 class="font-display text-display-md font-bold text-ink">数据台账</h2>
      <span class="label-caption text-muted ml-auto tabular-nums">{{ count($points) }} 条</span>
    </div>
    <x-data-table :points="$points" />
  </section>

  {{-- 附：数据溯源 --}}
  @if(count($sources) > 0)
    <section class="max-w-5xl mx-auto px-6 pb-12">
      <div class="hairline-b pb-3 mb-7 flex items-baseline gap-3">
        <span class="label-caption text-muted">附</span>
        <h2 class="font-display text-display-md font-bold text-ink">数据溯源</h2>
      </div>
      <ul class="space-y-3">
        @foreach($sources as $url => $label)
          <li class="flex gap-3 items-baseline">
            <span class="label-caption text-muted tabular-nums flex-shrink-0">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
            <a href="{{ $url }}" target="_blank" rel="noopener noreferrer"
               class="text-sm text-ink hover:text-accent transition-colors break-all">{{ $url }}</a>
          </li>
        @endforeach
      </ul>
    </section>
  @endif

  {{-- 口径说明 --}}
  <footer class="max-w-3xl mx-auto px-6 pb-20">
    <div class="border border-hairline p-6">
      <p class="label-caption text-muted mb-3">口径说明</p>
      <p class="text-xs leading-relaxed text-muted">
        信源为人民日报与央视《新闻联播》原文。区分"表述"与"事实"：官媒叙事为表述，台账数值为事实。
        每个数据点保留原文链接，可逐项回原文核对。未核验点为模型抽取失败时的正则命中片段，已单独标记，请谨慎引用。
      </p>
    </div>
  </footer>

</article>

@endsection
