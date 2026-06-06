<?php

namespace Tests\Feature;

use App\Models\User;
use App\Rules\UniqueCanonicalUsername;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class UsernameUniquenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_blocks_case_variant_duplicate_usernames(): void
    {
        User::factory()->create(['name' => 'Case Sensitive']);

        $this->expectException(QueryException::class);

        User::factory()->create(['name' => 'case sensitive']);
    }

    public function test_canonical_username_rule_rejects_case_variant_duplicates(): void
    {
        User::factory()->create(['name' => 'Case Sensitive']);

        $validator = Validator::make(
            ['name' => 'case sensitive'],
            ['name' => ['required', new UniqueCanonicalUsername]]
        );

        $this->assertTrue($validator->fails());
        $this->assertSame('The username has already been taken.', $validator->errors()->first('name'));
    }

    public function test_canonical_username_rule_ignores_the_current_user(): void
    {
        $user = User::factory()->create(['name' => 'Case Sensitive']);

        $validator = Validator::make(
            ['name' => 'case sensitive'],
            ['name' => ['required', new UniqueCanonicalUsername($user->id)]]
        );

        $this->assertFalse($validator->fails());
    }
}
