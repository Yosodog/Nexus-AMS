<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\AssertionFailedError;
use Throwable;

class EmulatedPreparesParityTest extends MySqlIntegrationTestCase
{
    private const NASTY_STRING = "O'Brien \\ \"quoted\" ? :name \\' --";

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['native', 'emulated'] as $name) {
            $config = config('database.connections.mysql');
            $config['options'] = ($config['options'] ?? []) + [PDO::ATTR_EMULATE_PREPARES => $name === 'emulated'];
            config(["database.connections.parity_{$name}" => $config]);
            DB::purge("parity_{$name}");
        }

        Schema::dropIfExists('emulation_parity');
        Schema::create('emulation_parity', function ($table): void {
            $table->id();
            $table->integer('quantity');
            $table->bigInteger('big');
            $table->decimal('price', 12, 2);
            $table->double('ratio');
            $table->boolean('flag');
            $table->string('label')->nullable();
            $table->text('note')->nullable();
            $table->json('payload')->nullable();
            $table->dateTime('seen_at')->nullable();
            $table->binary('blob')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('emulation_parity');
        DB::purge('parity_native');
        DB::purge('parity_emulated');

        parent::tearDown();
    }

    public function test_emulated_connection_really_uses_emulation(): void
    {
        $this->assertFalse((bool) DB::connection('parity_native')->getPdo()->getAttribute(PDO::ATTR_EMULATE_PREPARES));
        $this->assertTrue((bool) DB::connection('parity_emulated')->getPdo()->getAttribute(PDO::ATTR_EMULATE_PREPARES));
    }

    public function test_reads_and_writes_return_identical_values_and_types(): void
    {
        $results = [];

        foreach (['native', 'emulated'] as $name) {
            $db = DB::connection("parity_{$name}");
            $db->table('emulation_parity')->truncate();

            $db->table('emulation_parity')->insert([
                ['quantity' => 7, 'big' => PHP_INT_MAX, 'price' => '1234.50', 'ratio' => 0.1, 'flag' => true,
                    'label' => self::NASTY_STRING, 'note' => null, 'payload' => json_encode(['a' => [1, 2], 'b' => "x'y"]),
                    'seen_at' => '2026-11-26 12:34:56', 'blob' => "\x00\x01\xff'\\"],
                ['quantity' => -3, 'big' => 0, 'price' => '0.05', 'ratio' => 2.5, 'flag' => false,
                    'label' => null, 'note' => 'plain', 'payload' => null, 'seen_at' => null, 'blob' => null],
                ['quantity' => 9, 'big' => 1, 'price' => '9.99', 'ratio' => 3.0, 'flag' => true,
                    'label' => 'third', 'note' => null, 'payload' => null, 'seen_at' => null, 'blob' => null],
            ]);

            $db->table('emulation_parity')->where('quantity', 9)->update(['label' => self::NASTY_STRING, 'note' => 'upd']);

            $results[$name] = [
                'all' => $db->table('emulation_parity')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
                'bound_limit' => $db->table('emulation_parity')->orderBy('id')->limit(2)->offset(1)->pluck('id')->all(),
                'raw_limit' => $db->select('select id from emulation_parity order by id limit ? offset ?', [1, 1]),
                'by_label' => $db->table('emulation_parity')->where('label', self::NASTY_STRING)->orderBy('id')->pluck('id')->all(),
                'where_in' => $db->table('emulation_parity')->whereIn('id', range(1, 3000))->count(),
                'json' => $db->table('emulation_parity')->where('payload->b', "x'y")->value('id'),
                'null_cmp' => $db->table('emulation_parity')->whereNull('label')->pluck('id')->all(),
                'sum' => $db->table('emulation_parity')->sum('price'),
                'count' => $db->table('emulation_parity')->count(),
                'bool_bind' => $db->table('emulation_parity')->where('flag', true)->count(),
                'literal_question' => $db->select("select '?' as q, ':name' as n, ? as p", ['bound']),
            ];
        }

        $this->assertSame($this->describe($results['native']), $this->describe($results['emulated']));
        $this->assertEquals($results['native'], $results['emulated']);

        $first = $results['emulated']['all'][0];
        $this->assertSame(7, $first['quantity']);
        $this->assertSame('1234.50', $first['price']);
        $this->assertSame(self::NASTY_STRING, $first['label']);
        $this->assertNull($first['note']);
    }

    public function test_multiple_statements_are_rejected_when_emulation_disables_them(): void
    {
        config(['database.connections.parity_emulated.options' => config('database.connections.parity_emulated.options')
            + [PDO::MYSQL_ATTR_MULTI_STATEMENTS => false]]);
        DB::purge('parity_emulated');

        try {
            DB::connection('parity_emulated')->select('select 1; select 2', []);
            $this->fail('Multi-statement query was accepted.');
        } catch (AssertionFailedError $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->assertNotSame('', $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function describe(array $value): array
    {
        return array_map(fn ($v) => is_array($v) ? $this->describe($v) : get_debug_type($v).':'.(is_object($v) ? json_encode($v) : var_export($v, true)), $value);
    }
}
