<?php

namespace Tests\Unit\Services;

use App\Exceptions\UserErrorException;
use App\Models\Account;
use App\Models\Nation;
use App\Models\User;
use App\Services\AllianceMembershipService;
use App\Services\MemberTransferService;
use App\Services\PWHelperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\FeatureTestCase;

class MemberTransferServiceTest extends FeatureTestCase
{
    use RefreshDatabase;

    public function test_normalize_resources_fills_missing_resource_keys_with_zero(): void
    {
        $service = new MemberTransferService(app(AllianceMembershipService::class));

        $normalized = $this->invokePrivate($service, 'normalizeResources', [[
            'money' => 125,
            'food' => 50,
        ]]);

        $this->assertSame(125.0, $normalized['money']);
        $this->assertSame(50.0, $normalized['food']);
        $this->assertSame(0.0, $normalized['coal']);
        $this->assertSame(0.0, $normalized['aluminum']);
    }

    public function test_validate_request_rejects_same_account_transfers(): void
    {
        [$user, $account] = $this->createUserWithAccount();
        $service = new MemberTransferService(app(AllianceMembershipService::class));

        $this->expectException(UserErrorException::class);
        $this->expectExceptionMessage('You cannot transfer to the same account.');

        $this->invokePrivate($service, 'validateRequest', [
            $user,
            $account,
            $account,
            ['money' => 10] + $this->zeroResources(),
        ]);
    }

    public function test_validate_request_rejects_zero_value_transfers(): void
    {
        [$user, $fromAccount] = $this->createUserWithAccount(780002);
        [, $toAccount] = $this->createUserWithAccount(780003);
        cache()->forever('alliances:membership:ids', [777]);

        $service = new MemberTransferService(app(AllianceMembershipService::class));

        $this->expectException(UserErrorException::class);
        $this->expectExceptionMessage("You can't transfer nothing.");

        $this->invokePrivate($service, 'validateRequest', [
            $user,
            $fromAccount,
            $toAccount,
            $this->zeroResources(),
        ]);
    }

    public function test_validate_request_rejects_out_of_alliance_destinations(): void
    {
        [$user, $fromAccount] = $this->createUserWithAccount(780004, 777);
        [, $toAccount] = $this->createUserWithAccount(780005, 999);
        cache()->forever('alliances:membership:ids', [777]);

        $service = new MemberTransferService(app(AllianceMembershipService::class));

        $this->expectException(UserErrorException::class);
        $this->expectExceptionMessage('Transfers are only allowed to members in your alliance.');

        $this->invokePrivate($service, 'validateRequest', [
            $user,
            $fromAccount,
            $toAccount,
            ['money' => 10] + $this->zeroResources(),
        ]);
    }

    /**
     * @return array{0: User, 1: Account}
     */
    private function createUserWithAccount(int $nationId = 780001, int $allianceId = 777): array
    {
        $nation = Nation::factory()->create([
            'id' => $nationId,
            'alliance_id' => $allianceId,
            'alliance_position' => 'MEMBER',
        ]);

        $user = User::factory()->verified()->create([
            'nation_id' => $nation->id,
        ]);

        $account = new Account;
        $account->nation_id = $nation->id;
        $account->name = 'Primary';
        $account->money = 100;
        $account->save();

        return [$user, $account];
    }

    /**
     * @return array<string, float>
     */
    private function zeroResources(): array
    {
        return collect(PWHelperService::resources())
            ->mapWithKeys(fn (string $resource): array => [$resource => 0.0])
            ->all();
    }

    private function invokePrivate(object $object, string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $arguments);
    }
}
