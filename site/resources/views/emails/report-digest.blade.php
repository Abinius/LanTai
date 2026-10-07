{{--
  订阅推送邮件：兰台观局本期研判摘要。

  邮件客户端约束：
  - 不加载外部 CSS / 图片 / JS
  - 使用 inline style
  - HTML 结构用 table（老邮件客户端兼容性）
  - 链接必须有可点击的备用文本

  变量：
  - $recipient   User 对象（收件人）
  - $reports     Collection，每条含 period/title/summary/matched
  - $brand, $distributor, $slogan, $footerNote, $appUrl
--}}

<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>兰台观局 · 本期研判</title>
</head>
<body style="margin:0;padding:0;background-color:#f5f5f4;font-family:'Helvetica Neue',Helvetica,Arial,'PingFang SC','Microsoft YaHei',sans-serif;color:#1c1917;line-height:1.6;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f5f4;padding:32px 0;">
  <tr>
    <td align="center">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background-color:#ffffff;border:1px solid #e7e5e4;">
        {{-- 品牌头 --}}
        <tr>
          <td style="padding:32px 32px 20px;border-bottom:1px solid #e7e5e4;">
            <div style="font-size:11px;letter-spacing:2px;color:#7c3aed;font-weight:bold;text-transform:uppercase;">SUBSCRIPTION · DIGEST</div>
            <div style="font-size:22px;font-weight:900;color:#1c1917;margin-top:8px;">{{ $brand }}</div>
            <div style="font-size:13px;color:#78716c;margin-top:4px;">{{ $slogan }}</div>
          </td>
        </tr>

        {{-- 收件人问候 --}}
        <tr>
          <td style="padding:24px 32px 0;">
            <div style="font-size:14px;color:#44403c;">{{ $recipient->name }}，本期匹配到你订阅的 {{ $reports->count() }} 期研判。</div>
          </td>
        </tr>

        {{-- 报告卡列表 --}}
        @foreach($reports as $report)
          <tr>
            <td style="padding:24px 32px 0;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e7e5e4;">
                <tr>
                  <td style="padding:20px;">
                    {{-- 期数标签 --}}
                    <div style="font-size:11px;letter-spacing:1px;color:#78716c;font-weight:bold;">
                      {{ str_replace('-', ' ~ ', $report['period'] ?? '') }}
                      @if(!empty($report['kind']) && $report['kind'] === 'full')
                        · 深度报告
                      @else
                        · 简报
                      @endif
                    </div>
                    {{-- 标题 --}}
                    <div style="font-size:17px;font-weight:bold;color:#1c1917;margin-top:6px;line-height:1.4;">
                      {{ $report['title'] ?? '未命名' }}
                    </div>
                    {{-- 摘要 --}}
                    @if(!empty($report['summary']))
                    <div style="font-size:13px;color:#44403c;margin-top:10px;line-height:1.7;">
                      {{ $report['summary'] }}
                    </div>
                    @endif
                    {{-- 匹配标签 --}}
                    @php
                      $matchedDomains = $report['matched']['domains'] ?? [];
                      $matchedRegions = $report['matched']['regions'] ?? [];
                      $matchedTags = array_slice(array_merge($matchedDomains, $matchedRegions), 0, 4);
                    @endphp
                    @if(count($matchedTags) > 0)
                    <div style="margin-top:14px;">
                      @foreach($matchedTags as $tag)
                        <span style="display:inline-block;padding:3px 10px;background-color:#f3e8ff;color:#7c3aed;border-radius:3px;font-size:11px;margin-right:4px;font-weight:500;">
                          {{ $tag }}
                        </span>
                      @endforeach
                    </div>
                    @endif
                    {{-- 链接 --}}
                    <div style="margin-top:16px;">
                      <a href="{{ $appUrl }}/reports/{{ $report['period'] ?? '' }}"
                         style="display:inline-block;padding:9px 20px;background-color:#1c1917;color:#ffffff;text-decoration:none;font-size:13px;font-weight:600;border-radius:2px;">
                        读完整报告 &rarr;
                      </a>
                    </div>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
        @endforeach

        {{-- 页脚 --}}
        <tr>
          <td style="padding:32px 32px 24px;border-top:1px solid #e7e5e4;margin-top:24px;">
            <div style="font-size:12px;color:#78716c;line-height:1.7;">
              <div style="font-weight:600;color:#57534e;margin-bottom:6px;">{{ $footerNote ?? ($brand . ' 出品 · ' . $distributor . ' 分发') }}</div>
              <div>退订：进 <a href="{{ $appUrl }}/subscription" style="color:#7c3aed;">订阅设置</a> 关闭邮件推送。</div>
              <div style="margin-top:6px;">数据点均保留原文溯源，可逐项核对。</div>
            </div>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
