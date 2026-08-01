<?php

namespace Tests\Feature\Services;

use App\Exceptions\PWQueryFailedException;
use App\Services\AllianceQueryService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AllianceQueryServiceTest extends TestCase
{
    public function test_single_alliance_query_throws_a_controlled_exception_for_an_empty_response(): void
    {
        Http::fake([
            'https://pw.test/graphql*' => Http::response([
                'data' => [
                    'alliances' => [
                        'data' => [],
                    ],
                ],
            ]),
        ]);

        $this->expectException(PWQueryFailedException::class);
        $this->expectExceptionMessage('returned no usable data for alliance [9988]');

        AllianceQueryService::getAllianceById(9988);
    }
}
