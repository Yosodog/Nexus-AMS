<?php

namespace Tests\Unit\Jobs;

use App\Jobs\SendCityGrantRemindersJob;
use App\Models\CityGrant;
use App\Models\Nation;
use App\Services\AllianceMembershipService;
use App\Services\PWMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class SendCityGrantRemindersJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_reminder_message_and_preview_use_valid_link_bbcode(): void
    {
        $method = new ReflectionMethod(SendCityGrantRemindersJob::class, 'buildMessage');
        $message = $method->invoke(
            new SendCityGrantRemindersJob([], 'Apply now'),
            'Leader',
            'Apply now',
            'https://nexus.example/grants/city',
        );

        $this->assertStringContainsString(
            '[link=https://nexus.example/grants/city]here[/link]',
            $message,
        );
        $this->assertStringNotContainsString('[/here]', $message);

        $preview = file_get_contents(resource_path('views/admin/grants/cities.blade.php')) ?: '';

        $this->assertStringContainsString(
            '[link={link to apply for city grants}]here[/link]',
            $preview,
        );
        $this->assertStringNotContainsString('[/here]', $preview);
    }

    public function test_reminders_are_only_sent_to_nations_that_meet_custom_requirements(): void
    {
        $grant = CityGrant::query()->create([
            'description' => 'Reminder eligibility',
            'enabled' => true,
            'grant_amount' => 100,
            'city_number' => 6,
            'requirements' => [
                'group' => 'all',
                'rules' => [[
                    'field' => 'color',
                    'operator' => 'eq',
                    'value' => 'BLUE',
                    'message' => '',
                ]],
            ],
        ]);
        $eligible = Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
            'num_cities' => 5,
            'color' => 'BLUE',
        ]);
        Nation::factory()->create([
            'alliance_id' => 777,
            'alliance_position' => 'MEMBER',
            'num_cities' => 5,
            'color' => 'RED',
        ]);

        $membershipService = $this->createStub(AllianceMembershipService::class);
        $membershipService->method('getAllianceIds')->willReturn(collect([777]));
        $membershipService->method('contains')->willReturn(true);
        $this->app->instance(AllianceMembershipService::class, $membershipService);

        $messageService = $this->createMock(PWMessageService::class);
        $messageService->expects($this->once())
            ->method('sendMessage')
            ->with(
                $eligible->id,
                'City Grant Reminder',
                $this->callback(static fn (string $message): bool => str_contains($message, 'Apply now')),
            );

        (new SendCityGrantRemindersJob([$grant->id], 'Apply now'))
            ->handle($messageService, $membershipService);
    }
}
