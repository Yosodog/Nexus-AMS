<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\FeatureTestCase;

class LoginTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureIsolatedTestDatabase();
        Schema::dropAllTables();

        Schema::create('users', function ($table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->unsignedInteger('nation_id')->nullable();
            $table->boolean('is_admin')->default(false);
            $table->boolean('disabled')->default(false);
            $table->timestamp('last_active_at')->nullable();
            $table->string('verification_code')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('discord_verification_token')->nullable()->unique();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('roles', function ($table): void {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('protected')->default(false);
            $table->timestamps();
        });

        Schema::create('role_user', function ($table): void {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['user_id', 'role_id']);
        });

        Schema::create('audit_logs', function ($table): void {
            $table->id();
            $table->timestamp('occurred_at')->index();
            $table->uuid('request_id')->nullable()->index();
            $table->string('ip', 45)->nullable()->index();
            $table->string('user_agent', 512)->nullable();
            $table->string('actor_type')->index();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->index(['actor_type', 'actor_id']);
            $table->string('category')->index();
            $table->string('action')->index();
            $table->string('outcome')->index();
            $table->string('severity')->index();
            $table->string('message')->nullable();
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->index(['subject_type', 'subject_id']);
            $table->json('context')->nullable();
        });
    }

    public function test_users_can_log_in_with_mixed_case_usernames(): void
    {
        $user = User::query()->create([
            'name' => 'Browser Member',
            'email' => 'browser.member@example.test',
            'password' => Hash::make('password'),
            'verified_at' => now(),
            'email_verified_at' => now(),
        ]);

        $response = $this->post('/login', [
            'name' => 'Browser Member',
            'password' => 'password',
        ]);

        $response->assertRedirect(route('user.dashboard'));
        $this->assertAuthenticatedAs($user);
    }
}
