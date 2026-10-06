@extends('layouts.app')

@section('title', '我的情报 · ' . config('lantai.brand'))

@section('content')

@php
    $hasTags = ! empty($tags['domains']) || ! empty($tags['regions']);
@endphp

<section class="max-w-7xl mx-auto px-6 pt-16 pb-10">
  <p class="label-caption text-accent mb-4">MY BRIEFING</p>
  <h1 class="font-display text-display-lg font-black text-ink">我的情报</h1>

  @if($hasTags)
    <div class="mt-6 flex flex-wrap items-center gap-2">
      <span class="label-caption text-muted mr-1">领域</span>
      @if(empty($tags['domains']))<x-badge>不限</x-badge>@else
        @foreach($tags['domains'] as $domain)<x-badge>{{ $domain }}</x-badge>@endforeach
      @endif
      <span class="label-caption text-muted mx-1">地区</span>
      @if(empty($tags['regions']))<x-badge>不限</x-badge>@else
        @foreach($tags['regions'] as $region)<x-badge>{{ $region }}</x-badge>@endforeach
      @endif
    </div>
  @endif
</section>

@if(! $hasTags)
  <section class="max-w-3xl mx-auto px-6 pb-24 text-center">
    <div class="border border-hairline bg-surface px-8 py-16">
      <p class="font-display text-display-md font-bold text-ink">还没有兴趣标签</p>
      <p class="mt-4 text-sm text-ink-soft leading-relaxed">
        先选两条兴趣轴（领域 × 地区），命中它们的期数会汇总到这里，不用逐期翻找。
      </p>
      <div class="mt-8">
        <a href="{{ route('subscription.edit') }}" class="btn-ink">设置兴趣标签</a>
      </div>
    </div>
  </section>
@elseif(empty($briefings))
  <section class="max-w-3xl mx-auto px-6 pb-24 text-center">
    <div class="border border-hairline bg-surface px-8 py-16">
      <p class="font-display text-display-md font-bold text-ink">还没有命中的期数</p>
      <p class="mt-4 text-sm text-ink-soft leading-relaxed">
        当前标签下的两条轴没有同时被任何一期命中。领域与地区需同时满足，可放宽其中一条（不选即不限）。
      </p>
      <div class="mt-8">
        <a href="{{ route('subscription.edit') }}" class="btn-ink">调整兴趣标签</a>
      </div>
    </div>
  </section>
@else
  <section class="max-w-7xl mx-auto px-6 pb-6">
    <p class="border-b border-hairline pb-6 text-sm text-muted tabular-nums">
      命中 {{ count($briefings) }} 期 · 卡片上高亮的标签即命中项
    </p>
  </section>

  <section class="max-w-7xl mx-auto px-6 pb-6">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-8 gap-y-14">
      @foreach($briefings as $briefing)
        <x-report-card
          :period="$briefing['period']"
          :period_label="\App\Services\PublicationService::periodLabel($briefing['period'])"
          :title="$briefing['title']"
          :summary="$briefing['summary']"
          :source_count="$briefing['source_count']"
          :judgment_count="$briefing['judgment_count']"
          :prediction_count="$briefing['prediction_count']"
          :point_count="$briefing['point_count']"
          :domains="$briefing['domains']"
          :regions="$briefing['regions']"
          :matched_domains="$briefing['matched']['domains']"
          :matched_regions="$briefing['matched']['regions']" />
      @endforeach
    </div>
  </section>
@endif

@endsection
