<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * 邮箱统一按小写存储与查询。
     *
     * 注册、登录、找回密码三处都要用：SQLite 的字符串比较区分大小写
     * （MySQL 默认 utf8mb4_unicode_ci 不区分），任一处漏归一就会出现
     * 「注册得进、密码找不回」这类只在本地复现的怪象。
     */
    public static function normalizeEmail(?string $email): string
    {
        return Str::lower((string) $email);
    }

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * 当前生效的订阅（兴趣标签）。未设置时为 null。
     */
    public function subscription(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Subscription::class);
    }
}
