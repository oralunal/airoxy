<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('access_tokens', function (Blueprint $table) {
            $table->string('type')->default('oauth')->after('name');
        });

        // Backfill: mark any non-OAuth tokens as api_key
        DB::table('access_tokens')
            ->where('token', 'NOT LIKE', 'sk-ant-oat%')
            ->update(['type' => 'api_key']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('access_tokens', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
