<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>@yield('title', config('lantai.brand') . ' · 政策情报工作站')</title>
  <meta name="description" content="@yield('og-description', config('lantai.slogan') . '——以国字号舆论场与官方宏观数据为信源，产出少而准的政策判断。')">
  <meta name="theme-color" content="#FAFAF7">
  <meta name="keywords" content="政策情报,政策动态,智库,宏观经济,政策研判">

  {{-- 浏览器图标 --}}
  @include('partials.favicons')

  <!-- 微信 / 社交媒体分享（各页可用 og-title / og-description / og-url / og-image 覆盖） -->
  <meta property="og:type" content="article">
  <meta property="og:site_name" content="{{ config('lantai.brand') }} · {{ config('lantai.distributor') }}">
  <meta property="og:title" content="@yield('og-title', config('lantai.brand') . ' · ' . config('lantai.slogan'))">
  <meta property="og:description" content="@yield('og-description', config('lantai.slogan') . '——以国字号舆论场与官方宏观数据为信源，产出少而准的政策判断。')">
  <meta property="og:url" content="@yield('og-url', url('/'))">
  <meta property="og:image" content="@yield('og-image', asset(config('lantai.og_image')))">
  <meta property="og:locale" content="zh_CN">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="@yield('og-title', config('lantai.brand') . ' · ' . config('lantai.slogan'))">
  <meta name="twitter:description" content="@yield('og-description', config('lantai.slogan') . '——以国字号舆论场与官方宏观数据为信源，产出少而准的政策判断。')">
  <meta name="twitter:image" content="@yield('og-image', asset(config('lantai.og_image')))">

  <link rel="stylesheet" href="{{ asset('css/app.css') }}">
  @stack('styles')
  <link rel="preconnect" href="https://fonts.googleapis.cn">
  <link rel="preconnect" href="https://fonts.gstatic.cn" crossorigin>
  {{-- 拉丁字体（Playfair/Inter）：国内节点优先，失败自动切谷歌；中文用系统字体 --}}
  @include('partials.font-loader')
  <script defer src="{{ asset('js/alpine.min.js') }}"></script>
</head>
<body class="min-h-screen flex flex-col" x-data="{ menuOpen: false }"
      :class="menuOpen ? 'overflow-hidden' : ''">

  {{-- 右上角固定悬浮汉堡按钮 --}}
  <button type="button" @click="menuOpen = true" aria-label="打开菜单"
          class="fixed top-5 right-5 z-[200] w-11 h-11 flex items-center justify-center
                 bg-paper/85 backdrop-blur-md border border-hairline text-ink
                 hover:bg-ink hover:text-paper transition-colors duration-200">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="w-5 h-5">
      <line x1="3" y1="7" x2="21" y2="7"></line>
      <line x1="3" y1="12" x2="21" y2="12"></line>
      <line x1="3" y1="17" x2="21" y2="17"></line>
    </svg>
  </button>

  {{-- 全屏菜单弹窗（Signify 骨架复用：大标题 + 眉标副说明，刊物目录感） --}}
  <div x-show="menuOpen" x-cloak
       @keydown.escape.window="menuOpen = false"
       class="fixed inset-0 z-[200] bg-paper flex flex-col">
    <div class="flex items-center justify-between px-6 py-5 border-b border-hairline">
      <span class="label-caption text-muted">{{ config('lantai.brand') }}</span>
      <button type="button" @click="menuOpen = false" aria-label="关闭菜单"
              class="w-11 h-11 flex items-center justify-center text-ink hover:opacity-60 transition-opacity">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="w-6 h-6">
          <line x1="18" y1="6" x2="6" y2="18"></line>
          <line x1="6" y1="6" x2="18" y2="18"></line>
        </svg>
      </button>
    </div>
    <nav class="flex-1 overflow-y-auto">
      <div class="max-w-5xl mx-auto w-full px-6 py-10">
        @auth
          <div class="flex items-center justify-between pb-6 border-b border-hairline">
            <span class="label-caption text-muted">{{ auth()->user()->email }}</span>
            <form method="POST" action="{{ route('logout') }}">
              @csrf
              <button type="submit" class="label-caption text-muted hover:text-accent transition-colors">退出登录</button>
            </form>
          </div>
        @else
          <div class="flex items-center justify-between pb-6 border-b border-hairline">
            <span class="label-caption text-muted">访客</span>
            <div class="flex items-center gap-5">
              <a href="{{ route('login') }}" @click="menuOpen = false"
                 class="label-caption text-ink hover:text-accent transition-colors">登录</a>
              <a href="{{ route('register') }}" @click="menuOpen = false"
                 class="label-caption text-accent">加入</a>
            </div>
          </div>
        @endauth

        <div class="space-y-2">
          <a href="{{ route('publications.index') }}" @click="menuOpen = false"
             class="group block py-4 border-b border-hairline">
            <span class="font-display text-display-md font-bold text-ink group-hover:text-accent transition-colors">情报流</span>
            <span class="block label-caption text-muted mt-1">深度研判报告，逐期翻阅</span>
          </a>

          @auth
            <a href="{{ route('briefing.index') }}" @click="menuOpen = false"
               class="group block py-4 border-b border-hairline">
              <span class="font-display text-display-md font-bold text-ink group-hover:text-accent transition-colors">我的情报</span>
              <span class="block label-caption text-muted mt-1">按兴趣标签命中的期数</span>
            </a>
            <a href="{{ route('subscription.edit') }}" @click="menuOpen = false"
               class="group block py-4 border-b border-hairline">
              <span class="font-display text-display-md font-bold text-ink group-hover:text-accent transition-colors">订阅设置</span>
              <span class="block label-caption text-muted mt-1">兴趣标签：领域 × 地区</span>
            </a>
            @if(auth()->user()->isAdmin())
              <a href="{{ route('admin.sources') }}" @click="menuOpen = false"
                 class="group block py-4 border-b border-hairline">
                <span class="font-display text-display-md font-bold text-ink group-hover:text-accent transition-colors">信源状态</span>
                <span class="block label-caption text-muted mt-1">后台 · 各信源采集与覆盖期数</span>
              </a>
              <a href="{{ route('admin.diagnosis') }}" @click="menuOpen = false"
                 class="group block py-4 border-b border-hairline">
                <span class="font-display text-display-md font-bold text-ink group-hover:text-accent transition-colors">期数诊断</span>
                <span class="block label-caption text-muted mt-1">后台 · 耗时/台账/核验一览</span>
              </a>
            @endif
          @endauth
        </div>
      </div>
    </nav>
  </div>

  {{-- 品牌眉栏 --}}
  <header class="border-b border-hairline">
    <div class="max-w-7xl mx-auto px-6 py-6 flex items-baseline justify-between gap-6">
      <a href="{{ route('publications.index') }}" class="group">
        <span class="font-display text-2xl font-black text-ink tracking-tight group-hover:text-accent transition-colors">{{ config('lantai.brand') }}</span>
      </a>
      <span class="label-caption text-muted hidden sm:block">{{ config('lantai.slogan') }}</span>
    </div>
  </header>

  <main class="flex-1">
    @include('components.flash')
    @yield('content')
  </main>

  <footer class="border-t border-hairline">
    <div class="max-w-7xl mx-auto px-6 py-12 text-center space-y-2">
      <p class="font-display text-display-md font-bold text-ink">{{ config('lantai.slogan') }}</p>
      <p class="label-caption text-muted mt-3">{{ config('lantai.footer_note') }}</p>
      <p class="label-caption text-muted mt-1">数据来源：人民日报、央视《新闻联播》原文；每个论断可溯源核对</p>
    </div>
  </footer>

  @stack('scripts')

</body>
</html>
