<?php

namespace Tests\Feature\Console;

use App\Jobs\SyncNationsJob;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NationSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_uses_minimal_pagination_probe_and_batches_every_page(): void
    {
        Bus::fake();
        Http::fake([
            '*' => Http::response([
                'data' => [
                    'nations' => [
                        'data' => [['id' => 1]],
                        'paginatorInfo' => [
                            'perPage' => 100,
                            'count' => 1,
                            'lastPage' => 3,
                        ],
                    ],
                ],
            ]),
        ]);

        $this->artisan('sync:nations')
            ->expectsOutput('Queued 3 nation sync jobs in a batch.')
            ->assertSuccessful();

        Http::assertSent(function ($request): bool {
            $query = (string) $request->data()['query'];

            return str_contains($query, 'data { id }')
                && str_contains($query, 'paginatorInfo')
                && ! str_contains($query, 'nation_name')
                && ! str_contains($query, 'cities');
        });

        Bus::assertBatched(function (PendingBatch $batch): bool {
            $pages = $batch->jobs
                ->filter(fn (object $job): bool => $job instanceof SyncNationsJob)
                ->pluck('page')
                ->all();

            return $batch->name !== null
                && str_starts_with($batch->name, 'Nation Sync - ')
                && $pages === [1, 2, 3];
        });
    }
}
