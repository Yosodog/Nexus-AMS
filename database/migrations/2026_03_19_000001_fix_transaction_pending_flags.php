<?php

use App\Models\Transaction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Transaction::query()
            ->where('is_pending', true)
            ->where(function ($query) {
                $query->whereNotNull('refunded_at')
                    ->orWhereNotNull('sent_at')
                    ->orWhereNotNull('denied_at');
            })
            ->update([
                'is_pending' => false
            ]);
    }

    public function down(): void
    {
        // Irreversible data correction.
    }
};
