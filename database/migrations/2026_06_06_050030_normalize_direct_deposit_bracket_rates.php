<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MIN_TAX_RATE = 0.00;

    private const MAX_TAX_RATE = 100.00;

    /**
     * @return array<int, string>
     */
    private function rateFields(): array
    {
        return [
            'money',
            'coal',
            'oil',
            'uranium',
            'iron',
            'bauxite',
            'lead',
            'gasoline',
            'munitions',
            'steel',
            'aluminum',
            'food',
        ];
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('direct_deposit_tax_brackets')) {
            return;
        }

        foreach ($this->rateFields() as $field) {
            DB::table('direct_deposit_tax_brackets')
                ->where($field, '<', self::MIN_TAX_RATE)
                ->update([$field => self::MIN_TAX_RATE]);

            DB::table('direct_deposit_tax_brackets')
                ->where($field, '>', self::MAX_TAX_RATE)
                ->update([$field => self::MAX_TAX_RATE]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
