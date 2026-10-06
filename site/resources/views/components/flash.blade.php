@if(session('success') || session('error') || session('status'))
<div class="max-w-7xl mx-auto px-6 pt-6">
  @if(session('success'))
    <div class="border border-hairline border-l-4 border-l-status-success bg-surface px-5 py-4">
      <p class="text-sm text-status-success">{{ session('success') }}</p>
    </div>
  @endif
  @if(session('error'))
    <div class="border border-hairline border-l-4 border-l-status-danger bg-surface px-5 py-4">
      <p class="text-sm text-status-danger">{{ session('error') }}</p>
    </div>
  @endif
  @if(session('status'))
    <div class="border border-hairline bg-surface px-5 py-4">
      <p class="text-sm text-ink-soft">{{ session('status') }}</p>
    </div>
  @endif
</div>
@endif
