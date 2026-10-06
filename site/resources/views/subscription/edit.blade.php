@extends('layouts.app')

@section('title', '订阅设置 · ' . config('lantai.brand'))

@section('content')
@php
    // 台账口径三类与省级行政区分开呈现（口径含"全国"几乎命中全部期数）
    $scopes = array_intersect(['全国', '地区', '县域'], $regions);
    $provinces = array_diff($regions, $scopes);
@endphp

<section class="max-w-5xl mx-auto px-6 pt-16 pb-10">
  <p class="label-caption text-accent mb-4">SUBSCRIPTION</p>
  <h1 class="font-display text-display-lg font-black text-ink">订阅设置</h1>
  <p class="mt-4 text-ink-soft leading-relaxed">
    设两条兴趣轴。<span class="text-ink">领域</span>与<span class="text-ink">地区</span>都命中的期数才进入「我的情报」；
    某一轴不选即视为不限。
  </p>
</section>

<form method="POST" action="{{ route('subscription.update') }}">
  @csrf

  <section class="max-w-5xl mx-auto px-6 pb-14">
    <div class="flex items-baseline justify-between gap-4 border-b border-hairline pb-4">
      <h2 class="font-display text-display-md font-bold text-ink">领域</h2>
      <span class="label-caption text-muted">{{ count($tags['domains']) }} 已选 · 不选即不限</span>
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 pt-6">
      @foreach($domains as $domain)
        @include('partials.tag-option', [
            'name' => 'domains[]', 'value' => $domain,
            'checked' => in_array($domain, $tags['domains'], true),
        ])
      @endforeach
    </div>
  </section>

  <section class="max-w-5xl mx-auto px-6 pb-14">
    <div class="flex items-baseline justify-between gap-4 border-b border-hairline pb-4">
      <h2 class="font-display text-display-md font-bold text-ink">地区</h2>
      <span class="label-caption text-muted">{{ count($tags['regions']) }} 已选 · 不选即不限</span>
    </div>

    <div class="grid grid-cols-3 gap-3 pt-6">
      @foreach($scopes as $scope)
        @include('partials.tag-option', [
            'name' => 'regions[]', 'value' => $scope,
            'checked' => in_array($scope, $tags['regions'], true),
        ])
      @endforeach
    </div>

    <p class="label-caption text-muted mt-8 mb-3">省级行政区</p>
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
      @foreach($provinces as $province)
        @include('partials.tag-option', [
            'name' => 'regions[]', 'value' => $province,
            'checked' => in_array($province, $tags['regions'], true),
        ])
      @endforeach
    </div>
  </section>

  <div class="max-w-5xl mx-auto px-6 pb-20">
    <div class="flex items-center gap-5">
      <button type="submit" class="btn-ink">保存兴趣标签</button>
      <a href="{{ route('briefing.index') }}" class="btn-outline">看我的情报</a>
    </div>
    <p class="mt-4 text-xs text-muted">
      标签来自内核台账：领域由指标关键词命中，地区取台账口径。选「全国」几乎命中全部期数——多数期数都含全国口径数据。
    </p>
  </div>
</form>
@endsection
