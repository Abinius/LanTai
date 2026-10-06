<?php

use App\Http\Controllers\PublicationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 兰台观局 · 公开路由
|--------------------------------------------------------------------------
| 数据源为内核产物 JSON，无写操作。
| 订阅/认证（M5，Signify 兴趣标签订阅端）与情报后台另建。
*/

Route::get('/', [PublicationController::class, 'index'])->name('publications.index');
Route::get('/reports/{period}', [PublicationController::class, 'show'])->name('publications.show');
