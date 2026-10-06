{{--
  徽章 Badge（PRD §5.4 兰台观局特有）
  label-caption + border-hairline；强调用 accent。
  <x-badge>12 个数据源</x-badge>
  <x-badge :emphasized="true">本期主线</x-badge>
--}}

@props(['emphasized' => false])

<span {{ $attributes->merge(['class' => 'label-caption inline-flex items-center px-2.5 py-1 border whitespace-nowrap ' . ($emphasized ? 'border-accent text-accent' : 'border-hairline text-muted')]) }}>
  {{ $slot }}
</span>
