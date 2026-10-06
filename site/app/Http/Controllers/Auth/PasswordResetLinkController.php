<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): \Illuminate\Contracts\View\View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate(['email' => 'required|email']);
        // 与注册/登录同一套归一：否则大小写不同的邮箱查不到用户
        $request->merge(['email' => User::normalizeEmail($request->email)]);

        $status = Password::sendResetLink(
            $request->only('email')
        );

        // 用 'success' 而非 'status'：布局的全局 flash 按 success 着色
        return $status == Password::RESET_LINK_SENT
            ? back()->with('success', __($status))
            : back()->withInput($request->only('email'))
                ->withErrors(['email' => __($status)]);
    }
}
