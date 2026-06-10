<?php

namespace Tests\Unit\GraphQL\Models;

use App\GraphQL\Models\BankRecord;
use App\GraphQL\Models\BankRecords;
use App\GraphQL\Models\Cities;
use App\GraphQL\Models\City;
use App\GraphQL\Models\Nation;
use App\GraphQL\Models\Nations;
use App\GraphQL\Models\War;
use App\GraphQL\Models\Wars;
use Tests\UnitTestCase;

class GraphQLCollectionIteratorTest extends UnitTestCase
{
    public function test_nations_collection_counts_added_items_and_rewinds(): void
    {
        $first = new Nation;
        $first->id = 1;
        $second = new Nation;
        $second->id = 2;
        $nations = new Nations([$first]);

        $nations->add($second);

        $this->assertSame(2, $nations->count());
        $this->assertSame($first, $nations->current());
        $nations->next();
        $this->assertSame(1, $nations->key());
        $this->assertSame($second, $nations->current());
        $nations->next();
        $this->assertFalse($nations->valid());
        $nations->rewind();
        $this->assertSame($first, $nations->current());
    }

    public function test_cities_collection_counts_added_items(): void
    {
        $first = new City;
        $first->id = '10';
        $second = new City;
        $second->id = '11';
        $cities = new Cities([$first]);

        $cities->add($second);

        $this->assertSame(2, $cities->count());
        $this->assertSame($first, $cities->current());
        $cities->next();
        $this->assertSame($second, $cities->current());
    }

    public function test_bank_records_collection_counts_added_items(): void
    {
        $first = new BankRecord;
        $first->id = 100;
        $second = new BankRecord;
        $second->id = 101;
        $bankRecords = new BankRecords([$first]);

        $bankRecords->add($second);

        $this->assertSame(2, $bankRecords->count());
        $this->assertSame($first, $bankRecords->current());
        $bankRecords->next();
        $this->assertSame($second, $bankRecords->current());
    }

    public function test_wars_collection_iterates_added_items(): void
    {
        $first = new War;
        $first->id = 200;
        $second = new War;
        $second->id = 201;
        $wars = new Wars([$first]);

        $wars->add($second);

        $this->assertSame($first, $wars->current());
        $wars->next();
        $this->assertSame(1, $wars->key());
        $this->assertSame($second, $wars->current());
        $wars->next();
        $this->assertFalse($wars->valid());
        $wars->rewind();
        $this->assertSame($first, $wars->current());
    }
}
