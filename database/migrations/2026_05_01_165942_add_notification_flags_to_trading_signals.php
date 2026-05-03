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
        Schema::table('trading_signals', function (Blueprint $table) {
            $table->boolean('notified_near_sl')->default(false)->after('status');
            $table->boolean('notified_structure_break')->default(false)->after('notified_near_sl');
            $table->boolean('notified_tp')->default(false)->after('notified_structure_break');
            $table->boolean('notified_sl')->default(false)->after('notified_tp');
        });
    }

    public function down(): void
    {
        Schema::table('trading_signals', function (Blueprint $table) {
            $table->dropColumn(['notified_near_sl', 'notified_structure_break', 'notified_tp', 'notified_sl']);
        });
    }
};
