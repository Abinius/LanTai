@extends('layouts.app')

@section('title', '注册')

@section('content')
<div class="max-w-md mx-auto px-6 py-20">
  <p class="label-caption text-accent mb-4 text-center">JOIN</p>
  <h1 class="font-display text-display-lg font-black text-ink mb-10 text-center">注册</h1>

  <form method="POST" action="{{ route('register') }}" class="space-y-8">
    @csrf

    <div>
      <label for="email" class="label-caption text-muted">邮箱</label>
      <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="input-line placeholder:text-muted/60">
      @error('email') <p class="field-error">{{ $message }}</p> @enderror
    </div>

    <div>
      <label for="password" class="label-caption text-muted">密码</label>
      <input id="password" type="password" name="password" required autocomplete="new-password" placeholder="至少 6 个字符" class="input-line placeholder:text-muted/60">
      @error('password') <p class="field-error">{{ $message }}</p> @enderror
    </div>

    <div>
      <label for="password_confirmation" class="label-caption text-muted">确认密码</label>
      <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" placeholder="再次输入密码" class="input-line placeholder:text-muted/60">
    </div>

    <button type="submit" class="btn-ink w-full">注册</button>
  </form>

  <p class="mt-10 text-sm text-muted text-center">
    已有账号？<a href="{{ route('login') }}" class="text-ink hover:text-accent transition-colors">直接登录</a>
  </p>
</div>
@endsection
