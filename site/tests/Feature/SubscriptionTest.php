<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * 注册/登录与订阅标签保存。
 * 校验重点是 normalize()：脏输入（不在候选表、重复值）不得入库。
 */
class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_open_subscription_settings(): void
    {
        $this->get(route('subscription.edit'))->assertRedirect(route('login'));
    }

    public function test_first_registration_lands_on_subscription_settings(): void
    {
        $response = $this->post(route('register'), [
            'email' => 'reader@example.com',
            'password' => 'secret-1',
            'password_confirmation' => 'secret-1',
        ]);

        $response->assertRedirect(route('subscription.edit'));
        $this->assertAuthenticated();
    }

    public function test_logout_clears_session(): void
    {
        $user = $this->user();
        $this->actingAs($user)->post(route('logout'))->assertRedirect(route('publications.index'));
        $this->assertGuest();
    }

    public function test_settings_page_lists_candidates_and_marks_selected(): void
    {
        $user = $this->user(['投资'], ['山东']);

        $this->actingAs($user)
            ->get(route('subscription.edit'))
            ->assertOk()
            ->assertSee('投资')
            ->assertSee('山东')
            ->assertSee('1 已选');
    }

    public function test_save_filters_dirty_values_and_deduplicates(): void
    {
        $user = $this->user();

        $this->actingAs($user)->post(route('subscription.update'), [
            'domains' => ['投资', '投资', '投资部', '消费'],
            'regions' => ['山东', '山东省', '全国', '东京'],
        ])->assertRedirect();

        $sub = $user->refresh()->subscription;

        $this->assertSame(['投资', '消费'], $sub->domains);
        $this->assertSame(['山东', '全国'], $sub->regions);
    }

    public function test_save_replaces_previous_tags_instead_of_merging(): void
    {
        $user = $this->user(['投资'], ['山东']);

        $this->actingAs($user)->post(route('subscription.update'), [
            'domains' => ['就业'],
            'regions' => [],
        ]);

        $sub = $user->refresh()->subscription;

        $this->assertSame(['就业'], $sub->domains);
        $this->assertSame([], $sub->regions);
    }

    public function test_registered_user_can_login_again(): void
    {        $this->user(['投资']);

        $response = $this->post(route('login'), [
            'email' => 'reader@example.com',
            'password' => 'secret-1',
        ]);

        $response->assertRedirect(route('publications.index'));
        $this->assertAuthenticated();
        $this->assertSame('reader@example.com', Auth::user()->email);
    }

    public function test_login_accepts_differently_cased_email(): void
    {
        $this->post(route('register'), [
            'email' => 'Reader@Example.com',
            'password' => 'secret-1',
            'password_confirmation' => 'secret-1',
        ]);
        $this->assertDatabaseHas('users', ['email' => 'reader@example.com']);
        $this->post(route('logout'));

        $this->post(route('login'), [
            'email' => 'Reader@Example.com',
            'password' => 'secret-1',
        ])->assertRedirect(route('publications.index'));

        $this->assertAuthenticated();
    }

    /**
     * 注册把邮箱归一成小写存储，找回密码也必须同样归一，
     * 否则大小写不一致时 SQLite（BINARY 比较，区分大小写）查不到用户。
     */
    public function test_password_reset_accepts_differently_cased_email(): void
    {
        $this->post(route('register'), [
            'email' => 'Reader@Example.com',
            'password' => 'secret-1',
            'password_confirmation' => 'secret-1',
        ]);
        $this->post(route('logout'));

        $this->post(route('password.email'), ['email' => 'Reader@Example.com'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'reader@example.com']);
    }

    private function user(array $domains = [], array $regions = []): User
    {
        $user = User::create([
            'name' => 'reader',
            'email' => 'reader@example.com',
            'password' => 'secret-1',
        ]);

        app(\App\Services\SubscriptionService::class)->save($user, $domains, $regions);

        return $user;
    }
}
