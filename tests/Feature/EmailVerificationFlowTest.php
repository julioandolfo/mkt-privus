<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmailVerificationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_registrations_require_email_verification(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'Novo Cliente',
            'email' => 'novo@example.com',
            'brand_name' => 'Empresa Teste',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::where('email', 'novo@example.com')->firstOrFail();
        $this->assertFalse($user->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_unverified_user_is_redirected_from_dashboard(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('verification.notice'));
    }

    public function test_verified_user_reaches_dashboard(): void
    {
        config(['billing.enabled' => false]);
        $user = User::factory()->create(); // verificado por padrão

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }
}
