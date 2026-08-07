<?php

namespace Tests\Unit\Services;

use App\Services\SubscriptionEventValidator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SubscriptionEventValidatorTest extends TestCase
{
    private string $quarantineFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->quarantineFile = sys_get_temp_dir().'/nexus-subscription-validator-'.bin2hex(random_bytes(6)).'.jsonl';
        config()->set('subscriptions.ingestion.quarantine_file', $this->quarantineFile);
    }

    protected function tearDown(): void
    {
        if (is_file($this->quarantineFile)) {
            unlink($this->quarantineFile);
        }

        parent::tearDown();
    }

    #[DataProvider('supportedEventProvider')]
    public function test_it_accepts_every_existing_subscription_event(
        string $model,
        string $event,
        array $payload,
    ): void {
        $validated = app(SubscriptionEventValidator::class)->validateAndNormalize($model, $event, $payload);

        $this->assertSame("{$model}:{$event}", $validated->key());
        $this->assertCount(1, $validated->records);
        $this->assertSame((int) $payload['id'], $validated->records[0]['id']);
    }

    public function test_it_preserves_identity_and_record_normalization_behavior(): void
    {
        $validated = app(SubscriptionEventValidator::class)->validateAndNormalize(' WAR ', ' UPDATE ', [
            'id' => '101',
            'att_id' => '201',
            'turns_left' => '0',
            'att_peace' => '0',
            'att_gas_used' => '1.5',
            'unclassified_field' => 'preserved-for-parity',
        ]);

        $this->assertSame('war', $validated->model);
        $this->assertSame('update', $validated->event);
        $this->assertSame('war:update', $validated->key());
        $this->assertSame([[
            'id' => 101,
            'att_id' => 201,
            'turns_left' => 0,
            'att_peace' => false,
            'att_gas_used' => '1.5',
            'unclassified_field' => 'preserved-for-parity',
        ]], $validated->records);
    }

    public function test_it_accepts_an_empty_batch_for_a_supported_event(): void
    {
        $validated = app(SubscriptionEventValidator::class)->validateAndNormalize('nation', 'update', []);

        $this->assertSame([], $validated->records);
        $this->assertFileDoesNotExist($this->quarantineFile);
    }

    public function test_it_rejects_unsupported_events_with_the_existing_error_contract(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported subscription event [nation:snapshot].');

        app(SubscriptionEventValidator::class)->validateAndNormalize(' Nation ', ' Snapshot ', ['id' => 1]);
    }

    public function test_it_quarantines_invalid_records_and_returns_valid_siblings(): void
    {
        $validated = app(SubscriptionEventValidator::class)->validateAndNormalize('war', 'update', [
            null,
            ['id' => ['poison']],
            ['id' => '202', 'turns_left' => '4'],
        ]);

        $this->assertSame([[
            'id' => 202,
            'turns_left' => 4,
        ]], $validated->records);

        $quarantined = array_map(
            static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file($this->quarantineFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
        );

        $this->assertCount(2, $quarantined);
        $this->assertSame('record_must_be_an_object', $quarantined[0]['reason']);
        $this->assertStringStartsWith('validation_failed:', $quarantined[1]['reason']);
        $this->assertSame('war', $quarantined[1]['model']);
        $this->assertSame('update', $quarantined[1]['event']);
    }

    public function test_it_rejects_a_non_empty_batch_when_every_record_is_invalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Subscription payload contains no valid records for [nation:update].');

        app(SubscriptionEventValidator::class)->validateAndNormalize('nation', 'update', [
            ['nation_name' => 'Missing ID'],
        ]);
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>}>
     */
    public static function supportedEventProvider(): iterable
    {
        yield 'nation create' => ['nation', 'create', ['id' => '101']];
        yield 'nation update' => ['nation', 'update', ['id' => '101']];
        yield 'nation delete' => ['nation', 'delete', ['id' => '101']];
        yield 'alliance create' => ['alliance', 'create', ['id' => '101']];
        yield 'alliance update' => ['alliance', 'update', ['id' => '101']];
        yield 'alliance delete' => ['alliance', 'delete', ['id' => '101']];
        yield 'city create' => ['city', 'create', ['id' => '101']];
        yield 'city update' => ['city', 'update', ['id' => '101']];
        yield 'city delete' => ['city', 'delete', ['id' => '101']];
        yield 'war create' => ['war', 'create', ['id' => '101', 'att_id' => '201', 'def_id' => '301']];
        yield 'war update' => ['war', 'update', ['id' => '101']];
        yield 'war delete' => ['war', 'delete', ['id' => '101']];
        yield 'war attack create' => ['warattack', 'create', [
            'id' => '101',
            'att_id' => '201',
            'def_id' => '301',
            'war_id' => '401',
            'type' => 'GROUND',
        ]];
        yield 'account create' => ['account', 'create', ['id' => '101']];
        yield 'account update' => ['account', 'update', ['id' => '101']];
        yield 'account delete' => ['account', 'delete', ['id' => '101']];
    }
}
