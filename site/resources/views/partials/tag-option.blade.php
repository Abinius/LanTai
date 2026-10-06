{{--
  标签选项（订阅设置用）：checkbox + 文本的选项块。
  选中态由 has-[:checked] 驱动，避免 Alpine 冗余。
  props: name, value, checked
--}}

<label class="flex items-center gap-2.5 px-4 py-3 border border-hairline bg-surface cursor-pointer select-none
               has-[:checked]:border-accent has-[:checked]:bg-accent/5 transition-colors duration-150">
  <input type="checkbox" name="{{ $name }}" value="{{ $value }}"
         class="h-4 w-4 accent-accent flex-shrink-0"
         {{ $checked ? 'checked' : '' }}>
  <span class="text-sm text-ink">{{ $value }}</span>
</label>
