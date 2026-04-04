<?php

namespace Tests\Unit\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AuditRequestContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\FeatureTestCase;

class AuditLoggerSecurityTest extends FeatureTestCase
{
    use RefreshDatabase;

    public function test_record_sanitizes_control_characters_and_merges_request_metadata(): void
    {
        $subject = User::factory()->create();
        $requestContext = Mockery::mock(AuditRequestContext::class);
        $requestContext->shouldReceive('requestId')->andReturn("req-123\n");
        $requestContext->shouldReceive('ip')->andReturn("127.0.0.1\n");
        $requestContext->shouldReceive('userAgent')->andReturn("Browser\tName");
        $requestContext->shouldReceive('routeName')->andReturn('admin.settings');
        $requestContext->shouldReceive('method')->andReturn('POST');
        $requestContext->shouldReceive('actor')->andReturn([
            'type' => "user\n",
            'id' => $subject->id,
            'name' => "Alice\tAdmin",
        ]);

        $logger = new AuditLogger($requestContext);
        $logger->record(
            category: "security\n",
            action: "trusted_device_revoked\t",
            subject: $subject,
            context: [
                'details' => "line 1\r\nline 2",
                'nested' => [
                    'value' => "bad\x07value",
                ],
            ],
            message: "Message\r\nBody"
        );

        $log = AuditLog::query()->sole();

        $this->assertSame("req-123\n", $log->request_id);
        $this->assertSame('127.0.0.1', $log->ip);
        $this->assertSame('Browser Name', $log->user_agent);
        $this->assertSame('user', $log->actor_type);
        $this->assertSame('Alice Admin', $log->actor_name);
        $this->assertSame('security', $log->category);
        $this->assertSame('trusted_device_revoked', $log->action);
        $this->assertSame('Message  Body', $log->message);
        $this->assertSame(User::class, $log->subject_type);
        $this->assertSame((string) $subject->id, $log->subject_id);
        $this->assertSame('admin.settings', $log->context['request']['route']);
        $this->assertSame('POST', $log->context['request']['method']);
        $this->assertSame('line 1  line 2', $log->context['details']);
        $this->assertSame('bad value', $log->context['nested']['value']);
    }

    public function test_record_uses_sanitized_actor_override_when_provided(): void
    {
        $requestContext = Mockery::mock(AuditRequestContext::class);
        $requestContext->shouldReceive('requestId')->andReturn(null);
        $requestContext->shouldReceive('ip')->andReturn(null);
        $requestContext->shouldReceive('userAgent')->andReturn(null);
        $requestContext->shouldReceive('routeName')->andReturn(null);
        $requestContext->shouldReceive('method')->andReturn(null);
        $requestContext->shouldReceive('actor')->never();

        $logger = new AuditLogger($requestContext);
        $logger->record(
            category: 'security',
            action: 'manual_override',
            actorOverride: [
                'type' => "adm\nin",
                'id' => 'not-numeric',
                'name' => "Sec\tOps",
            ]
        );

        $log = AuditLog::query()->sole();

        $this->assertSame('adm in', $log->actor_type);
        $this->assertNull($log->actor_id);
        $this->assertSame('Sec Ops', $log->actor_name);
    }
}
