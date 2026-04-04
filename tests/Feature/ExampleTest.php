<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\FeatureTestCase;

class ExampleTest extends FeatureTestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        Route::get('/_testing/health', fn () => response('ok'));

        $response = $this->get('/_testing/health');

        $response->assertStatus(200);
    }
}
