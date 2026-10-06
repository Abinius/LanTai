<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): \Illuminate\Contracts\View\View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * 不发验证邮件：注册即登录。站内无 SMTP 收件场景，
     * 需要验证时再接 EmailVerification 通知。
     */
    public function store(Request $request): RedirectResponse
    {
        // 邮箱统一转为小写存储
        $request->merge(['email' => User::normalizeEmail($request->email)]);

        $request->validate([
            'email' => 'required|string|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::min(6)],
        ]);

        $user = User::create([
            // 注册不填姓名：以邮箱前缀作为默认展示名
            'name' => Str::before($request->email, '@'),
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        Auth::login($user);

        // 首次注册先设兴趣标签：不设标签「我的情报」永远是空的
        return redirect()->intended(
            $user->subscription ? route('publications.index') : route('subscription.edit')
        );
    }
}
