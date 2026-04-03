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
        Schema::create('request_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('access_token_id')->nullable()->constrained('access_tokens')->nullOnDelete();
            $table->foreignId('api_key_id')->nullable()->constrained('anthropic_api_keys')->nullOnDelete();
            $table->string('model');
            $table->string('endpoint')->default('/v1/messages');
            $table->boolean('is_stream')->default(false);
            $table->integer('input_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->integer('cache_creation_input_tokens')->nullable();
            $table->integer('cache_read_input_tokens')->nullable();
            $table->decimal('estimated_cost_usd', 10, 8)->nullable();
            $table->integer('status_code');
            $table->text('error_message')->nullable();
            $table->integer('duration_ms');
            $table->timestamp('requested_at');
            $table->timestamp('created_at')->nullable();

            $table->index('requested_at');
            $table->index('access_token_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('request_logs');
    }
};
