<?php

namespace Tests\Unit\GraphQL\Models;

use App\GraphQL\Models\BankRecord;
use Tests\UnitTestCase;

class BankRecordHydrationTest extends UnitTestCase
{
    public function test_build_with_json_defaults_optional_resource_fields(): void
    {
        $record = new BankRecord;

        $record->buildWithJSON((object) [
            'id' => 9001,
            'date' => '2026-06-01T12:00:00+00:00',
            'sender_id' => 1001,
            'sender_type' => 1,
            'receiver_id' => 777,
            'receiver_type' => 2,
            'banker_id' => 42,
        ]);

        $this->assertSame(9001, $record->id);
        $this->assertSame('2026-06-01T12:00:00+00:00', $record->date);
        $this->assertSame(1001, $record->sender_id);
        $this->assertNull($record->note);
        $this->assertSame(0.0, $record->money);
        $this->assertSame(0.0, $record->coal);
        $this->assertSame(0.0, $record->food);
        $this->assertSame(0, $record->tax_id);
    }

    public function test_build_with_json_preserves_present_resource_fields(): void
    {
        $record = new BankRecord;

        $record->buildWithJSON((object) [
            'id' => 9002,
            'date' => '2026-06-02T12:00:00+00:00',
            'sender_id' => 1002,
            'sender_type' => 1,
            'receiver_id' => 778,
            'receiver_type' => 2,
            'banker_id' => 43,
            'note' => 'Tax payment',
            'money' => 1000,
            'coal' => 1,
            'oil' => 2,
            'uranium' => 3,
            'iron' => 4,
            'bauxite' => 5,
            'lead' => 6,
            'gasoline' => 7,
            'munitions' => 8,
            'steel' => 9,
            'aluminum' => 10,
            'food' => 11,
            'tax_id' => 123,
        ]);

        $this->assertSame('Tax payment', $record->note);
        $this->assertSame(1000.0, $record->money);
        $this->assertSame(1.0, $record->coal);
        $this->assertSame(11.0, $record->food);
        $this->assertSame(123, $record->tax_id);
    }
}
