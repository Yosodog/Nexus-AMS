<?php

use App\Support\Database\WorldSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        WorldSchema::create('market_trades', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('type', 16);
            $table->string('resource', 24);
            $table->string('side', 8);
            $table->unsignedBigInteger('amount');
            $table->unsignedInteger('price');
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->unsignedBigInteger('receiver_id')->nullable();
            $table->unsignedBigInteger('original_trade_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('accepted_at');
            $table->timestamps();

            $table->index(['resource', 'side', 'accepted_at'], 'market_trades_resource_side_accepted_index');
            $table->index('accepted_at');
        });

        WorldSchema::create('market_price_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('basis');
            $table->timestamp('window_started_at');
            $table->timestamp('window_ended_at');
            $table->timestamp('calculated_at')->index();
            $table->timestamps();
        });

        WorldSchema::create('market_price_snapshot_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_price_snapshot_id')
                ->constrained('market_price_snapshots')
                ->cascadeOnDelete();
            $table->string('resource', 24);
            $table->decimal('acquisition_price', 14, 2);
            $table->decimal('liquidation_price', 14, 2);
            $table->unsignedInteger('acquisition_trade_count')->default(0);
            $table->unsignedInteger('liquidation_trade_count')->default(0);
            $table->unsignedBigInteger('acquisition_volume')->default(0);
            $table->unsignedBigInteger('liquidation_volume')->default(0);
            $table->boolean('acquisition_fallback')->default(false);
            $table->boolean('liquidation_fallback')->default(false);
            $table->timestamps();

            $table->unique(['market_price_snapshot_id', 'resource'], 'market_snapshot_resource_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        WorldSchema::dropIfExists('market_price_snapshot_items');
        WorldSchema::dropIfExists('market_price_snapshots');
        WorldSchema::dropIfExists('market_trades');
    }
};
