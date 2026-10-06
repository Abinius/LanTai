@extends('layouts.app')

@section('title', '忘记密码')

@section('content')
<div class="max-w-md mx-auto px-6 py-20">
  <p class="label-caption text-accent mb-4 text-center">FORGOT PASSWORD</p>
  <h1 class="font-display text-display-lg font-black text-ink mb-6 text-center">忘记密码</h1>
  <p class="text-sm text-ink-soft leading-relaxed mb-10">输入注册邮箱，我们将发送重置链接。</p>

  <form method="POST" action="{{ route('password.email') }}" class="space-y-8">
    @csrf
    <div>
      <label for="email" class="label-caption text-muted">邮箱</label>
      <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="input-line placeholder:text-muted/60">
      @error('email') <p class="field-error">{{ $message }}</p> @enderror
    </div>
    <button type="submit" class="btn-ink w-full">发送重置链接</button>
  </form>

  <p class="mt-10 text-sm text-muted text-center">
    <a href="{{ route('login') }}" class="text-ink hover:text-accent transition-colors">← 返回登录</a>
  </p>
</div>
@endsection
