<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * 订阅推送邮件：把匹配到的最新期数摘要推到订阅用户邮箱。
 *
 * 邮件内容：品牌头 + 每条报告的标题/摘要/匹配标签 + 链接进站点看完整报告 + 页脚。
 * 报告数据来自 SubscriptionService::briefingsFor() 命中的期数摘要数组，
 * 每条形如 ['period'=>..., 'title'=>..., 'summary'=>..., 'domains'=>[], 'regions'=>[],
 *          'matched'=>['domains'=>[], 'regions'=>[]], ...]。
 *
 * 走 HTML 单通道（邮件客户端 HTML 支持度已高）；模板用 inline style，不用 Tailwind CDN
 * （邮件客户端不加载外部资源）。
 */
class ReportDigest extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $recipient,
        public Collection $reports,
    ) {}

    public function envelope(): Envelope
    {
        $titles = $this->reports->pluck('title')->implode(' / ');
        $subject = $titles ? '兰台观局 · ' . mb_substr($titles, 0, 40) : '兰台观局 · 本期研判';

        return new Envelope(
            subject: $subject,
            from: new Address(
                config('mail.from.address'),
                config('lantai.brand'),
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.report-digest',
            with: [
                'recipient' => $this->recipient,
                'reports' => $this->reports,
                'brand' => config('lantai.brand'),
                'distributor' => config('lantai.distributor'),
                'slogan' => config('lantai.slogan'),
                'footerNote' => config('lantai.footer_note'),
                'appUrl' => config('app.url'),
            ],
        );
    }
}
