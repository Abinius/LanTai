{{--
  数据表 DataTable（PRD §5.4 兰台观局特有）
  无边框、仅 hairline 分隔；tabular-nums；表后附"读法"。

  props: points（ledger.points 数组）, note（读法说明）
--}}

@props([
    'points',
    'note' => '读法：数值保留原文口径，不做单位换算；同比为原文表述，"-" 表示原文未给。每个数据点可点回原文核对。',
])

@if(count($points) === 0)
  <p class="py-12 text-center text-muted text-sm">本期台账为空。</p>
@else
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead>
        <tr class="border-b border-hairline-strong">
          <th class="text-left py-3 pr-4 label-caption text-muted font-normal whitespace-nowrap">指标</th>
          <th class="text-right py-3 pr-4 label-caption text-muted font-normal whitespace-nowrap">数值</th>
          <th class="text-right py-3 pr-4 label-caption text-muted font-normal whitespace-nowrap">同比</th>
          <th class="text-left py-3 pr-4 label-caption text-muted font-normal whitespace-nowrap">口径</th>
          <th class="text-left py-3 pr-4 label-caption text-muted font-normal whitespace-nowrap">机构</th>
          <th class="text-right py-3 label-caption text-muted font-normal whitespace-nowrap">原文</th>
        </tr>
      </thead>
      <tbody>
        @foreach($points as $p)
          <tr class="border-b border-hairline {{ !empty($p['llm_unverified']) ? 'bg-status-warning/5' : '' }}">
            <td class="py-3 pr-4 text-ink align-top">
              {{ $p['indicator'] ?? '—' }}
              @if(!empty($p['llm_unverified']))
                <x-badge class="ml-2 align-middle">待核验</x-badge>
              @endif
            </td>
            <td class="py-3 pr-4 text-right align-top tabular-nums font-semibold whitespace-nowrap">
              {{ $p['value'] ?? '—' }}{{ $p['unit'] ? ' ' . e($p['unit']) : '' }}
            </td>
            <td class="py-3 pr-4 text-right align-top tabular-nums text-ink-soft whitespace-nowrap">
              {{ $p['yoy'] ?? '—' }}
            </td>
            <td class="py-3 pr-4 text-ink-soft align-top">
              {{ $p['scope'] ?? '—' }}
            </td>
            <td class="py-3 pr-4 text-ink-soft align-top whitespace-nowrap">
              {{ $p['agency'] ?? '—' }}
            </td>
            <td class="py-3 text-right align-top">
              <a href="{{ $p['source_url'] ?? '#' }}" target="_blank" rel="noopener noreferrer"
                 class="label-caption text-accent hover:underline whitespace-nowrap">↗ 原文</a>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  <p class="mt-5 text-xs text-muted leading-relaxed">{{ $note }}</p>
@endif
