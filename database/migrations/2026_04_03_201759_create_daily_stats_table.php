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
        Schema::create('daily_stats', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('access_token_id')->nullable()->constrained('access_tokens')->nullOnDelete();
            $table->string('model')->nullable();
            $table->integer('total_requests')->default(0);
            $table->integer('successful_requests')->default(0);
            $table->integer('failed_requests')->default(0);
            $table->bigInteger('total_input_tokens')->default(0);
            $table->bigInteger('total_output_tokens')->default(0);
            $table->bigInteger('total_cache_creation_tokens')->default(0);
            $table->bigInteger('total_cache_read_tokens')->default(0);
            $table->decimal('total_estimated_cost_usd', 12, 8)->default(0);
            $table->timestamps();

            $table->unique(['date', 'access_token_id', 'model']);
            $table->index('date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_stats');
    }
};
