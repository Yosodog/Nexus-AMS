<?php

namespace Tests\Unit\Services;

use App\Services\PWMessageService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PWMessageServiceTest extends TestCase
{
    public function test_send_message_returns_false_when_api_key_is_missing(): void
    {
        Config::set('services.pw.api_key', null);
        Log::spy();

        $service = new PWMessageService;

        $this->assertFalse($service->sendMessage(123456, 'Subject', 'Body'));

        Http::assertNothingSent();
        Log::shouldHaveReceived('error')->once();
    }
}
