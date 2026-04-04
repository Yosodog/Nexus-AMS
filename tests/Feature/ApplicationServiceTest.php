<?php

namespace Tests\Feature;

use App\Services\AllianceMembershipService;
use App\Services\AlliancePositionService;
use App\Services\ApplicationService;
use Tests\TestCase;

class ApplicationServiceTest extends TestCase
{
    public function test_join_url_uses_primary_alliance_id(): void
    {
        config()->set('services.pw.alliance_id', 4321);

        $service = new class(app(AllianceMembershipService::class), $this->createMock(AlliancePositionService::class)) extends ApplicationService
        {
            public function publicJoinUrl(): string
            {
                return $this->joinUrl();
            }
        };

        $this->assertSame(
            'https://politicsandwar.com/alliance/join/id=4321',
            $service->publicJoinUrl()
        );
    }
}
