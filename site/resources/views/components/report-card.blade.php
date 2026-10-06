{{--
  报告卡 ReportCard（PRD §5.4 兰台观局特有）
  替代 Signify 的"人物卡"：无封面，用大标题 + 眉标日期做主视觉，保持刊物感。

  props: period, period_label, title, summary,
         source_count, judgment_count, prediction_count, point_count
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
])

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

  <div class="mt-auto pt-7 flex flex-wrap gap-2">
    <x-badge>{{ $judgment_count }} 条矛盾判断</x-badge>
    <x-badge>{{ $prediction_count }} 条趋势预测</x-badge>
    <x-badge :emphasized="true">{{ $source_count }} 个数据源</x-badge>
  </div>
</a>
