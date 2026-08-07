<?php

namespace Tests\Unit\Services\Applications;

use App\Exceptions\ApplicationException;
use App\Exceptions\PWEntityDoesNotExist;
use App\GraphQL\Models\Nation as GraphQlNation;
use App\Models\Nation;
use App\Services\Applications\ApplicationNationLookup;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class ApplicationNationLookupTest extends TestCase
{
    public function test_it_maps_the_local_snapshot_to_the_graphql_contract(): void
    {
        $local = new Nation([
            'id' => 123,
            'alliance_id' => 777,
            'alliance_position' => 'APPLICANT',
            'alliance_position_id' => 2,
            'leader_name' => 'Example Leader',
        ]);

        $mapped = (new ApplicationNationLookup)->mapLocalNation($local);

        $this->assertSame(123, $mapped->id);
        $this->assertSame(777, $mapped->alliance_id);
        $this->assertSame('APPLICANT', $mapped->alliance_position);
        $this->assertSame(2, $mapped->alliance_position_id);
        $this->assertSame('Example Leader', $mapped->leader_name);
    }

    public function test_live_lookup_returns_the_query_result(): void
    {
        $nation = new GraphQlNation;
        $nation->id = 123;

        $result = (new ApplicationNationLookup)->fetchLive(
            123,
            fn (): GraphQlNation => $nation,
            'https://example.test/join',
        );

        $this->assertSame($nation, $result);
    }

    public function test_missing_nation_is_translated_to_the_application_contract(): void
    {
        try {
            (new ApplicationNationLookup)->fetchLive(
                404,
                fn (): never => throw new PWEntityDoesNotExist,
                'https://example.test/join',
            );
            $this->fail('Expected the nation lookup to fail.');
        } catch (ApplicationException $exception) {
            $this->assertSame('nation_not_found', $exception->error);
            $this->assertSame(404, $exception->status);
            $this->assertSame(['join_url' => 'https://example.test/join'], $exception->context);
        }
    }

    public function test_provider_failure_is_logged_and_returned_as_a_safe_temporary_error(): void
    {
        Log::spy();

        try {
            (new ApplicationNationLookup)->fetchLive(
                123,
                fn (): never => throw new RuntimeException('provider diagnostic'),
                'https://example.test/join',
            );
            $this->fail('Expected the nation lookup to fail.');
        } catch (ApplicationException $exception) {
            $this->assertSame('nation_lookup_failed', $exception->error);
            $this->assertSame(503, $exception->status);
            $this->assertSame('Unable to validate the nation at this time.', $exception->getMessage());
        }

        Log::shouldHaveReceived('error')
            ->once()
            ->with('Failed to fetch nation for application', [
                'nation_id' => 123,
                'error' => 'provider diagnostic',
            ]);
    }
}
