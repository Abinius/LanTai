<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\BriefingController;
use App\Http\Controllers\PublicationController;
use App\Http\Controllers\SubscriptionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 兰台观局 · 公开路由与订阅端路由
|--------------------------------------------------------------------------
| 情报流与报告详情为公开路由，数据源为内核产物 JSON，无写操作。
| 订阅端（M5）需登录：订阅设置与我的情报。认证路由见 auth.php。
| 情报后台（PRD §3.7，信源管理/诊断）最小版：只读，登录用户可访问。
*/

Route::get('/', [PublicationController::class, 'index'])->name('publications.index');
Route::get('/reports/{period}', [PublicationController::class, 'show'])->name('publications.show');

Route::middleware('auth')->group(function () {
    Route::get('/subscription', [SubscriptionController::class, 'edit'])->name('subscription.edit');
    Route::post('/subscription', [SubscriptionController::class, 'update'])->name('subscription.update');
    Route::get('/briefing', [BriefingController::class, 'index'])->name('briefing.index');

    // 情报后台（PRD §3.7 最小版）
    Route::get('/admin/sources', [AdminController::class, 'sources'])->name('admin.sources');
    Route::get('/admin/diagnosis', [AdminController::class, 'diagnosis'])->name('admin.diagnosis');
});
