<?php

namespace Tests\Unit\Jobs;

use App\Jobs\SendCityGrantRemindersJob;
use ReflectionMethod;
use Tests\TestCase;

class SendCityGrantRemindersJobTest extends TestCase
{
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
}
