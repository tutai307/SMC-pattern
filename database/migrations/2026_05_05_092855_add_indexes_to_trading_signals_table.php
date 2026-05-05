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
            $table->index('symbol');
            $table->index('status');
            $table->index('filled_at');
            $table->index(['symbol', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('trading_signals', function (Blueprint $table) {
            $table->dropIndex(['symbol']);
            $table->dropIndex(['status']);
            $table->dropIndex(['filled_at']);
            $table->dropIndex(['symbol', 'status']);
            $table->dropIndex(['created_at']);
        });
    }
};
