@props(['paginator'])

@if($paginator && $paginator->lastPage() > 1)
<div class="flex items-center justify-center gap-6 py-10">
  @if($paginator->onFirstPage())
    <span class="label-caption text-muted opacity-50">← 上一页</span>
  @else
    <a href="{{ $paginator->previousPageUrl() }}" class="label-caption text-ink-soft hover:text-ink">← 上一页</a>
  @endif
  <span class="text-sm text-muted tabular-nums">第 {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }} 页</span>
  @if($paginator->hasMorePages())
    <a href="{{ $paginator->nextPageUrl() }}" class="label-caption text-ink-soft hover:text-ink">下一页 →</a>
  @else
    <span class="label-caption text-muted opacity-50">下一页 →</span>
  @endif
</div>
@endif
