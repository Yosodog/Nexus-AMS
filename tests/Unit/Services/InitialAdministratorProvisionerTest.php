<?php

namespace Tests\Unit\Services;

use App\Models\Role;
use App\Models\User;
use App\Services\InitialAdministratorProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class InitialAdministratorProvisionerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_protected_fully_authorized_administrator(): void
    {
        $created = app(InitialAdministratorProvisioner::class)->provision([
            'email' => 'owner@example.com',
            'password' => 'correct horse battery staple',
            'nation_id' => 12345,
        ]);

        $this->assertTrue($created);
        $user = User::query()->where('email', 'owner@example.com')->firstOrFail();
        $role = Role::query()->where('name', 'default admin')->firstOrFail();

        $this->assertTrue((bool) $user->is_admin);
        $this->assertFalse((bool) $user->disabled);
        $this->assertTrue(Hash::check('correct horse battery staple', $user->password));
        $this->assertTrue($role->protected);
        $this->assertTrue($user->roles()->whereKey($role->getKey())->exists());
        $this->assertContains('manage-system', $role->permissionEntries()->all());
        $this->assertCount(count(config('permissions')), $role->permissionEntries());
        $this->assertTrue(DB::table('direct_deposit_tax_brackets')->where('city_number', 0)->exists());
        $this->assertTrue(DB::table('mmr_tiers')->where('city_count', 0)->exists());
        $this->assertGreaterThan(0, DB::table('mmr_settings')->count());
        $this->assertTrue(DB::table('pages')->where('slug', 'apply')->exists());
    }

    public function test_it_is_idempotent_for_the_same_administrator_identity(): void
    {
        $provisioner = app(InitialAdministratorProvisioner::class);
        $attributes = [
            'email' => 'owner@example.com',
            'password' => 'correct horse battery staple',
            'nation_id' => 12345,
        ];

        $this->assertTrue($provisioner->provision($attributes));
        $this->assertFalse($provisioner->provision($attributes));
        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, Role::query()->where('name', 'default admin')->count());
    }

    public function test_it_refuses_to_replace_an_existing_administrator(): void
    {
        $provisioner = app(InitialAdministratorProvisioner::class);
        $provisioner->provision([
            'email' => 'owner@example.com',
            'password' => 'correct horse battery staple',
            'nation_id' => 12345,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A different Nexus administrator already exists.');

        $provisioner->provision([
            'email' => 'replacement@example.com',
            'password' => 'another secure password',
            'nation_id' => 67890,
        ]);
    }
}
