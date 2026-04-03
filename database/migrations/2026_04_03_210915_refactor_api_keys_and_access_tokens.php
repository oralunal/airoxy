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
        Schema::rename('anthropic_api_keys', 'api_keys');

        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn(['api_key', 'usage_order', 'last_used_at']);
        });

        Schema::table('api_keys', function (Blueprint $table) {
            $table->string('key')->unique()->after('name');
        });

        Schema::table('access_tokens', function (Blueprint $table) {
            $table->integer('usage_order')->default(0)->after('refresh_fail_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('access_tokens', function (Blueprint $table) {
            $table->dropColumn('usage_order');
        });

        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn('key');
        });

        Schema::table('api_keys', function (Blueprint $table) {
            $table->text('api_key')->after('name');
            $table->integer('usage_order')->default(0);
            $table->timestamp('last_used_at')->nullable();
        });

        Schema::rename('api_keys', 'anthropic_api_keys');
    }
};
