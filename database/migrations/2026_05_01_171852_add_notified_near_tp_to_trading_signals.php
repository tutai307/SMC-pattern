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
            $table->boolean('notified_near_tp')->default(false)->after('notified_near_sl');
        });
    }

    public function down(): void
    {
        Schema::table('trading_signals', function (Blueprint $table) {
            $table->dropColumn('notified_near_tp');
        });
    }
};
