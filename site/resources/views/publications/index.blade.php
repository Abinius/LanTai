@extends('layouts.app')

@section('title', '情报流 · ' . config('lantai.brand'))

@section('content')

<section class="max-w-7xl mx-auto px-6 pt-16 pb-10 text-center">
  <p class="label-caption text-accent mb-4">{{ config('lantai.brand') }} · INTELLIGENCE</p>
  <h1 class="font-display text-display-lg font-black text-ink">情报流</h1>
  <p class="mt-4 text-ink-soft max-w-2xl leading-relaxed mx-auto">
    以国字号舆论场与官方宏观数据为信源，经采集、抽取、三维交叉研判出刊。<span class="text-ink">少而准，条条可溯源。</span>
  </p>
</section>

<section class="max-w-7xl mx-auto px-6 pb-10">
  <form method="GET" action="{{ route('publications.index') }}"
        class="border-b border-hairline pb-6 flex flex-col md:flex-row gap-5 md:items-end">
    <div class="flex-1">
      <label for="q" class="label-caption text-muted">检索</label>
      <input id="q" type="text" name="q" value="{{ $q }}"
             placeholder="检索指标、判断、原文表述……" class="input-line">
    </div>
    <button type="submit" class="btn-ink flex-shrink-0">检索</button>
    @if($q !== '')
      <a href="{{ route('publications.index') }}"
         class="btn-outline flex-shrink-0 self-stretch flex items-center">清空</a>
    @endif
  </form>
  <p class="mt-6 text-sm text-muted tabular-nums">
    共 {{ $publications->total() }} 期
    @if($q !== '') <span>· 命中「{{ $q }}」</span> @endif
  </p>
</section>

<section class="max-w-7xl mx-auto px-6 pb-6">
  @if($publications->isEmpty())
    <div class="py-24 text-center">
      <p class="font-display text-display-md font-bold text-ink">
        @if($q !== '') 未找到相关报告 @else 尚无出刊报告 @endif
      </p>
      <p class="mt-3 text-muted">
        @if($q !== '')
          试试换关键词，或直接翻阅全部期数
        @else
          内核尚未运行，执行 <code class="text-ink">python pipeline/run.py</code> 出刊后此处展示
        @endif
      </p>
    </div>
  @else
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-8 gap-y-14">
      @foreach($publications as $pub)
        <x-report-card
          :period="$pub['period']"
          :period_label="\App\Services\PublicationService::periodLabel($pub['period'])"
          :title="$pub['title']"
          :summary="$pub['summary']"
          :source_count="$pub['source_count']"
          :judgment_count="$pub['judgment_count']"
          :prediction_count="$pub['prediction_count']"
          :point_count="$pub['point_count']" />
      @endforeach
    </div>
  @endif
</section>

<div class="max-w-7xl mx-auto px-6 pb-16">
  <x-pagination :paginator="$publications" />
</div>

@endsection
