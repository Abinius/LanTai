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
    某一轴不选即视为不限。可选择邮件推送，新期数自动推到注册邮箱。
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

  <section class="max-w-5xl mx-auto px-6 pb-14">
    <div class="flex items-baseline justify-between gap-4 border-b border-hairline pb-4">
      <h2 class="font-display text-display-md font-bold text-ink">推送设置</h2>
      <span class="label-caption text-muted">
        {{ $push['channel'] === 'none' ? '不推送' : '邮件 · ' . ($push['freq'] === 'daily' ? '每日' : '每周') }}
      </span>
    </div>

    <p class="text-sm text-ink-soft mt-4 mb-5 leading-relaxed">
      开启后，新期数摘要将自动推送到注册邮箱；点邮件里的链接进站点看完整报告。
      存量订阅默认「不推送」，需主动选择。
    </p>

    <p class="label-caption text-muted mb-3">推送渠道</p>
    <div class="grid grid-cols-2 gap-3">
      @foreach($channels as $channel)
        @php
          $labels = ['none' => '不推送', 'email' => '邮件推送'];
          $descriptions = ['none' => '只在站点内查看', 'email' => '新期数推到注册邮箱'];
        @endphp
        <label class="flex flex-col gap-1.5 px-4 py-3 border border-hairline bg-surface cursor-pointer select-none
                       has-[:checked]:border-accent has-[:checked]:bg-accent/5 transition-colors duration-150">
          <input type="radio" name="channel" value="{{ $channel }}"
                 class="h-4 w-4 accent-accent"
                 {{ $push['channel'] === $channel ? 'checked' : '' }}>
          <span class="text-sm font-medium text-ink">{{ $labels[$channel] }}</span>
          <span class="text-xs text-muted">{{ $descriptions[$channel] }}</span>
        </label>
      @endforeach
    </div>

    @if($push['channel'] === 'email')
    <p class="label-caption text-muted mt-6 mb-3">推送频率</p>
    <div class="grid grid-cols-2 gap-3">
      @foreach($freqs as $freq)
        @php
          $freqLabels = ['daily' => '每日 9 点', 'weekly' => '每周一 9 点'];
          $freqDescs = ['daily' => '每天有新期数即推', 'weekly' => '每周汇总一次'];
        @endphp
        <label class="flex flex-col gap-1.5 px-4 py-3 border border-hairline bg-surface cursor-pointer select-none
                       has-[:checked]:border-accent has-[:checked]:bg-accent/5 transition-colors duration-150">
          <input type="radio" name="freq" value="{{ $freq }}"
                 class="h-4 w-4 accent-accent"
                 {{ $push['freq'] === $freq ? 'checked' : '' }}>
          <span class="text-sm font-medium text-ink">{{ $freqLabels[$freq] }}</span>
          <span class="text-xs text-muted">{{ $freqDescs[$freq] }}</span>
        </label>
      @endforeach
    </div>
    @else
    <input type="hidden" name="freq" value="{{ $push['freq'] }}">
    @endif
  </section>

  <div class="max-w-5xl mx-auto px-6 pb-20">
    <div class="flex items-center gap-5">
      <button type="submit" class="btn-ink">保存订阅设置</button>
      <a href="{{ route('briefing.index') }}" class="btn-outline">看我的情报</a>
    </div>
    <p class="mt-4 text-xs text-muted">
      标签来自内核台账：领域由指标关键词命中，地区取台账口径。选「全国」几乎命中全部期数——多数期数都含全国口径数据。
    </p>
  </div>
</form>
@endsection
