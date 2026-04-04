<?php

namespace App\Support;

use App\Models\DiscordAccount;
use App\Models\Nation;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class BrowserTestBootstrap
{
    /**
     * Reset the lightweight browser-test schema and seed stable personas.
     *
     * @return array{admin: User, member: User}
     */
    public function resetAndSeed(): array
    {
        $this->guardAgainstNonTestDatabases();

        $this->recreateSchema();

        return DB::transaction(function (): array {
            Setting::query()->create(['key' => 'require_discord_verification', 'value' => '1']);
            Setting::query()->create(['key' => 'require_mfa_all_users', 'value' => '0']);
            Setting::query()->create(['key' => 'require_mfa_admins', 'value' => '0']);

            $memberNation = Nation::query()->create([
                'id' => 200001,
                'nation_name' => 'Browser Member Nation',
                'leader_name' => 'Browser Member',
                'discord' => 'browser-member',
                'flag' => 'https://example.test/member-flag.png',
            ]);

            $adminNation = Nation::query()->create([
                'id' => 200002,
                'nation_name' => 'Browser Admin Nation',
                'leader_name' => 'Browser Admin',
                'discord' => 'browser-admin',
                'flag' => 'https://example.test/admin-flag.png',
            ]);

            $member = User::factory()
                ->verified()
                ->create([
                    'name' => 'Browser Member',
                    'email' => 'browser.member@example.test',
                    'nation_id' => $memberNation->id,
                    'last_active_at' => now(),
                ]);

            $admin = User::factory()
                ->verified()
                ->admin()
                ->create([
                    'name' => 'Browser Admin',
                    'email' => 'browser.admin@example.test',
                    'nation_id' => $adminNation->id,
                    'last_active_at' => now(),
                ]);

            DiscordAccount::factory()->create([
                'user_id' => $member->id,
                'discord_id' => '111111111111111111',
                'discord_username' => 'browser-member',
            ]);

            DiscordAccount::factory()->create([
                'user_id' => $admin->id,
                'discord_id' => '222222222222222222',
                'discord_username' => 'browser-admin',
            ]);

            $adminRole = Role::query()->create([
                'name' => 'Browser Admin Role',
                'protected' => false,
            ]);

            DB::table('role_permissions')->insert([
                'role_id' => $adminRole->id,
                'permission' => 'view-users',
            ]);

            $admin->roles()->attach($adminRole);

            return [
                'admin' => $admin->fresh(),
                'member' => $member->fresh(),
            ];
        });
    }

    private function recreateSchema(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ($this->tablesToDrop() as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();

        $this->createUsersTable();
        $this->createNationsTable();
        $this->createSettingsTable();
        $this->createDiscordAccountsTable();
        $this->createTrustedDevicesTable();
        $this->createPersonalAccessTokensTable();
        $this->createRolesTables();
        $this->createAuditLogsTable();
        $this->createPendingWorkflowTables();
    }

    private function guardAgainstNonTestDatabases(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('Browser test bootstrap may only run in the testing environment.');
        }

        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        $mysqlDatabase = (string) config('database.connections.mysql.database');
        $normalizedDatabase = strtolower($database);

        if ($connection !== 'sqlite') {
            throw new RuntimeException('Browser test bootstrap may only run against an isolated SQLite database.');
        }

        if (
            $database === ''
            || $database === ':memory:'
            || $database === database_path('database.sqlite')
            || $database === $mysqlDatabase
            || (! str_contains($normalizedDatabase, 'test') && ! str_contains($normalizedDatabase, 'browser'))
        ) {
            throw new RuntimeException('Browser test bootstrap refused to run against a non-isolated database.');
        }
    }

    /**
     * @return list<string>
     */
    private function tablesToDrop(): array
    {
        return [
            'role_user',
            'role_permissions',
            'roles',
            'audit_logs',
            'personal_access_tokens',
            'trusted_devices',
            'discord_accounts',
            'rebuilding_requests',
            'war_aid_requests',
            'loans',
            'grant_applications',
            'city_grant_requests',
            'transactions',
            'settings',
            'users',
            'nations',
        ];
    }

    private function createUsersTable(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
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
    }

    private function createNationsTable(): void
    {
        Schema::create('nations', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('nation_name')->nullable();
            $table->string('leader_name')->nullable();
            $table->unsignedInteger('alliance_id')->nullable();
            $table->string('discord')->nullable();
            $table->string('flag')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    private function createSettingsTable(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    private function createDiscordAccountsTable(): void
    {
        Schema::create('discord_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('discord_id');
            $table->string('discord_username');
            $table->timestamp('linked_at');
            $table->timestamp('unlinked_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    private function createTrustedDevicesTable(): void
    {
        Schema::create('trusted_devices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('token_hash');
            $table->string('user_agent_hash');
            $table->string('user_agent')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    private function createPersonalAccessTokensTable(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    private function createRolesTables(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('protected')->default(false);
            $table->timestamps();
        });

        Schema::create('role_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['user_id', 'role_id']);
        });

        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('permission');
            $table->primary(['role_id', 'permission']);
        });
    }

    private function createAuditLogsTable(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
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

    private function createPendingWorkflowTables(): void
    {
        Schema::create('transactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('from_account_id')->nullable();
            $table->unsignedBigInteger('to_account_id')->nullable();
            $table->unsignedInteger('nation_id')->nullable();
            $table->string('transaction_type')->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_pending')->default(false);
            $table->boolean('requires_admin_approval')->default(false);
            $table->string('pending_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('city_grant_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('nation_id');
            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('pending_key')->nullable();
            $table->timestamps();
        });

        Schema::create('grant_applications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('nation_id');
            $table->unsignedBigInteger('grant_id')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('pending_key')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('loans', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('nation_id');
            $table->unsignedBigInteger('account_id')->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->unsignedInteger('term_weeks')->default(0);
            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('pending_key')->nullable();
            $table->decimal('remaining_balance', 15, 2)->default(0);
            $table->decimal('weekly_interest_paid', 15, 2)->default(0);
            $table->decimal('scheduled_weekly_payment', 15, 2)->default(0);
            $table->decimal('past_due_amount', 15, 2)->default(0);
            $table->decimal('accrued_interest_due', 15, 2)->default(0);
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('next_due_date')->nullable();
            $table->timestamps();
        });

        Schema::create('war_aid_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('nation_id');
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('pending_key')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('denied_at')->nullable();
            $table->timestamps();
        });

        Schema::create('rebuilding_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('cycle_id')->default(1);
            $table->unsignedInteger('nation_id');
            $table->unsignedBigInteger('account_id')->nullable();
            $table->unsignedBigInteger('tier_id')->nullable();
            $table->unsignedInteger('city_count_snapshot')->default(0);
            $table->decimal('target_infrastructure_snapshot', 10, 2)->default(0);
            $table->decimal('estimated_amount', 15, 2)->default(0);
            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('pending_key')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('denied_at')->nullable();
            $table->timestamps();
        });
    }
}
