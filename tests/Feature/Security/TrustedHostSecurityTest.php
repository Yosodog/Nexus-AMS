<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Notifications\NationVerification;
use App\Notifications\PasswordResetNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TrustedHostSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_configured_application_host_is_trusted(): void
    {
        $configuredHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $patterns = app(TrustHosts::class)->hosts();

        $this->assertIsString($configuredHost);
        $this->assertNotSame('', $configuredHost);
        $this->assertTrue($this->matchesTrustedHost($configuredHost, $patterns));
        $this->assertFalse($this->matchesTrustedHost('attacker.example', $patterns));
        $this->assertFalse($this->matchesTrustedHost('subdomain.'.$configuredHost, $patterns));
    }

    public function test_security_notification_links_always_use_the_configured_origin(): void
    {
        $user = User::factory()->create([
            'email' => 'security@example.test',
            'nation_id' => 887001,
            'verification_code' => null,
        ]);

        app('url')->setRequest(Request::create('https://attacker.example/poisoned'));

        $resetPayload = (new PasswordResetNotification('reset-token'))->toPNW($user);
        $verification = new NationVerification($user);
        $verificationPayload = $verification->toPNW($user);
        $configuredOrigin = rtrim((string) config('app.url'), '/');

        $this->assertStringContainsString($configuredOrigin.'/reset-password/reset-token', $resetPayload['message']);
        $this->assertStringContainsString($configuredOrigin.'/verify/'.$verification->verification_code, $verificationPayload['message']);
        $this->assertStringNotContainsString('attacker.example', $resetPayload['message']);
        $this->assertStringNotContainsString('attacker.example', $verificationPayload['message']);
    }

    public function test_forged_host_is_rejected_before_a_password_reset_message_is_created(): void
    {
        $user = User::factory()->create(['nation_id' => 887002]);
        Notification::fake();
        $this->app['env'] = 'production';

        $this->withSession(['_token' => 'test-token'])
            ->post('http://attacker.example'.route('password.email', absolute: false), [
                '_token' => 'test-token',
                'nation_id' => $user->nation_id,
            ])
            ->assertBadRequest();

        Notification::assertNothingSent();
    }

    public function test_configured_host_can_request_a_password_reset(): void
    {
        $user = User::factory()->create(['nation_id' => 887003]);
        Notification::fake();
        $this->app['env'] = 'production';
        $configuredOrigin = rtrim((string) config('app.url'), '/');

        $this->withSession(['_token' => 'test-token'])
            ->post($configuredOrigin.route('password.email', absolute: false), [
                '_token' => 'test-token',
                'nation_id' => $user->nation_id,
            ])
            ->assertRedirect();

        Notification::assertSentTo($user, PasswordResetNotification::class);
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private function matchesTrustedHost(string $host, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match('{'.$pattern.'}i', $host) === 1) {
                return true;
            }
        }

        return false;
    }
}
