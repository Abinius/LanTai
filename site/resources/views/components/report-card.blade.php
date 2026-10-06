{{--
  报告卡 ReportCard（PRD §5.4 兰台观局特有）
  替代 Signify 的"人物卡"：无封面，用大标题 + 眉标日期做主视觉，保持刊物感。

  props: period, period_label, title, summary,
         source_count, judgment_count, prediction_count, point_count,
         domains, regions（订阅标签，情报流与我的情报共用）,
         matched_domains, matched_regions（我的情报专用，高亮命中的标签）
  标签最多显示 6 个，其余折叠为计数，避免卡片被标签淹没。
--}}

@props([
    'period',
    'period_label',
    'title',
    'summary' => null,
    'source_count' => 0,
    'judgment_count' => 0,
    'prediction_count' => 0,
    'point_count' => 0,
    'domains' => null,
    'regions' => null,
    'matched_domains' => null,
    'matched_regions' => null,
])

@php
    $tags = array_merge(
        array_map(fn (string $t) => [$t, in_array($t, $matched_domains ?? [], true)], $domains ?? []),
        array_map(fn (string $t) => [$t, in_array($t, $matched_regions ?? [], true)], $regions ?? [])
    );
@endphp

<a href="{{ route('publications.show', $period) }}"
   class="group block h-full border border-hairline bg-surface p-7 flex flex-col
          hover:border-hairline-strong transition-colors duration-200">
  <div class="flex items-baseline justify-between gap-3">
    <span class="label-caption text-muted tabular-nums">{{ $period_label }}</span>
    <span class="label-caption text-muted tabular-nums flex-shrink-0">{{ $point_count }} 数据点</span>
  </div>

  <h3 class="font-display text-xl font-bold text-ink leading-snug mt-6
             group-hover:text-accent transition-colors duration-200">
    {{ $title }}
  </h3>

  @if($summary)
    <p class="mt-4 text-sm text-ink-soft leading-relaxed line-clamp-3">{{ $summary }}</p>
  @endif

  @if(! empty($tags))
    <div class="mt-5 flex flex-wrap gap-1.5">
      @foreach(array_slice($tags, 0, 6) as [$tag, $hit])
        <x-badge :emphasized="$hit">{{ $tag }}</x-badge>
      @endforeach
      @if(count($tags) > 6)
        <x-badge>+{{ count($tags) - 6 }}</x-badge>
      @endif
    </div>
  @endif

  <div class="mt-auto pt-7 flex flex-wrap gap-2">
    <x-badge>{{ $judgment_count }} 条矛盾判断</x-badge>
    <x-badge>{{ $prediction_count }} 条趋势预测</x-badge>
    <x-badge :emphasized="true">{{ $source_count }} 个数据源</x-badge>
  </div>
</a>
