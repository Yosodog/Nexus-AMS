<?php

use App\Models\Offshore;
use App\Models\OffshoreTransfer;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offshore_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('source_type');
            $table->foreignIdFor(Offshore::class, 'source_offshore_id')->nullable()->constrained()->nullOnDelete();
            $table->string('destination_type');
            $table->foreignIdFor(Offshore::class, 'destination_offshore_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload');
            $table->string('status')->default(OffshoreTransfer::STATUS_PENDING);
            $table->text('message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'destination_type']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offshore_transfers');
    }
};
