<?php

namespace Tests\Feature\Milcom;

use App\Services\Milcom\ReadinessRefreshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReadinessRefreshServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function large_refreshes_use_bounded_concurrent_requests(): void
    {
        config()->set('services.pw.endpoint', 'https://pw.test/graphql');
        config()->set('services.pw.api_key', 'testing-key');
        $requestedChunks = [];

        Http::fake(function ($request) use (&$requestedChunks) {
            $query = (string) $request->data()['query'];
            $isLastChunk = str_contains($query, 'id: [1501]');
            $requestedChunks[] = $isLastChunk ? 'last' : 'first';
            $nationId = $isLastChunk ? 1_501 : 1_001;

            return Http::response([
                'data' => [
                    'nations' => [
                        'data' => [[
                            'id' => $nationId,
                            'nation_name' => 'Refresh Nation '.$nationId,
                            'leader_name' => 'Refresh Leader '.$nationId,
                            'continent' => 'NA',
                            'color' => 'blue',
                        ]],
                    ],
                ],
            ]);
        });

        $nationIds = range(1_001, 1_501);
        $result = app(ReadinessRefreshService::class)->refresh($nationIds);

        $this->assertSame(['first', 'last'], $requestedChunks);
        $this->assertSame([1_001, 1_501], $result->refreshedFrom($nationIds));
        $this->assertCount(499, $result->missingFrom($nationIds));
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_contains(
            (string) $request->data()['query'],
            'first: 500',
        ));
        Http::assertSent(fn ($request): bool => str_contains(
            (string) $request->data()['query'],
            'first: 1',
        ));
        Http::assertNotSent(fn ($request): bool => str_contains(
            (string) $request->data()['query'],
            'paginatorInfo',
        ));
    }
}
