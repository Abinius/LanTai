<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 订阅表。PRD §9 原定字段为 tags / channel / freq。
 *
 * 实际落地差异：
 * - tags 拆为 domains（领域）+ regions（地区）——PRD §3.9 的「兴趣标签 = 领域 × 地区」
 *   本身就是两轴，单列 tags 数组会让匹配逻辑无法区分维度。
 * - channel / freq 暂未建列：站内尚无推送通道（无 SMTP 收件与推送任务），
 *   当前是「我的情报」拉取式浏览。推送落地时再加列，migrations 增量即可。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('domains')->default('[]');
            $table->json('regions')->default('[]');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
