<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trading_signals', function (Blueprint $table) {
            // Only mt5_close_price is missing — all other columns already exist
            $table->decimal('mt5_close_price', 18, 8)->nullable()->after('mt5_ticket');
        });
    }

    public function down(): void
    {
        Schema::table('trading_signals', function (Blueprint $table) {
            $table->dropColumn('mt5_close_price');
        });
    }
};
