<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('check_batches', function (Blueprint $table) {
            $table->timestamp('last_tick_at')->nullable()->after('include_all_records');
            $table->timestamp('queue_handoff_at')->nullable()->after('last_tick_at');
        });
    }

    public function down(): void
    {
        Schema::table('check_batches', function (Blueprint $table) {
            $table->dropColumn(['last_tick_at', 'queue_handoff_at']);
        });
    }
};
