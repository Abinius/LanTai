<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * subscriptions 表加推送设置列。
 *
 * PRD §3.9 承诺「推送到我的情报」，M5 阶段只做拉取式（用户主动刷新），
 * 本轮 W3 补上邮件推送通道，subscriptions 增量加三列：
 * - channel: 推送渠道（none | email）。默认 none 保持不推，避免存量用户被突然打扰。
 * - freq: 推送频率（daily | weekly）。默认 weekly 是政策情报产品的合理默认。
 * - last_pushed_at: 上次推送时间，用于幂等判断「24h/7d 内不重复推」。
 *
 * 存量订阅行 channel 全部默认 'none'，即不推——用户必须主动改订阅设置才进推送。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('channel', 20)->default('none');
            $table->string('freq', 20)->default('weekly');
            $table->timestamp('last_pushed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['channel', 'freq', 'last_pushed_at']);
        });
    }
};
