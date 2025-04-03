<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('taxes', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary(); // Game Tax ID
            $table->timestamp('date')->useCurrent();

            $table->unsignedInteger('sender_id');

            $table->unsignedInteger('receiver_id');
            $table->unsignedTinyInteger('receiver_type');

            // Resources
            $table->float('money', 20, 2)->default(0);
            $table->float('coal', 20, 2)->default(0);
            $table->float('oil', 20, 2)->default(0);
            $table->float('uranium', 20, 2)->default(0);
            $table->float('iron', 20, 2)->default(0);
            $table->float('bauxite', 20, 2)->default(0);
            $table->float('lead', 20, 2)->default(0);
            $table->float('gasoline', 20, 2)->default(0);
            $table->float('munitions', 20, 2)->default(0);
            $table->float('steel', 20, 2)->default(0);
            $table->float('aluminum', 20, 2)->default(0);
            $table->float('food', 20, 2)->default(0);

            $table->unsignedInteger('tax_id')->nullable();

            $table->timestamps();

            // Optional: Add indexes or foreign keys as needed
            $table->index('sender_id');
            $table->index('receiver_id');
            $table->index('tax_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('taxes');
    }
};
