<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\ThrottleRegistrationRequests;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Tests\TestCase;

class RegistrationRateLimitTest extends TestCase
{
    public function test_registration_page_is_not_throttled_by_submission_middleware(): void
    {
        $this->assertContains(
            ThrottleRegistrationRequests::class,
            app('router')->getRoutes()->getByName('register.store')->middleware(),
        );
        $this->assertContains(
            ThrottleRegistrationRequests::class,
            app('router')->getRoutes()->getByName('register')->middleware(),
        );

        $request = Request::create('/register', 'GET');
        $request->setRouteResolver(
            fn () => app('router')->getRoutes()->getByName('register'),
        );

        $response = app(ThrottleRegistrationRequests::class)->handle(
            $request,
            fn () => response(status: 204),
        );

        $this->assertSame(204, $response->getStatusCode());
    }

    public function test_registration_submission_is_rate_limited_before_user_validation(): void
    {
        $creator = new class implements CreatesNewUsers
        {
            public int $attempts = 0;

            public function create(array $input): never
            {
                $this->attempts++;

                throw ValidationException::withMessages([
                    'nation_id' => 'Registration validation reached the live membership check.',
                ]);
            }
        };

        $this->app->instance(CreatesNewUsers::class, $creator);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.40'])
                ->postJson('/register', [])
                ->assertUnprocessable();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.40'])
            ->postJson('/register', [])
            ->assertTooManyRequests();

        $this->assertSame(5, $creator->attempts);
    }
}
