<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backtest_runs', function (Blueprint $table) {
            $table->id();
            // Params
            $table->string('symbol', 20);
            $table->string('timeframe', 10);
            $table->string('htf', 10);
            $table->date('from_date');
            $table->date('to_date');
            $table->string('method', 20)->default('smc');
            $table->decimal('rr', 4, 2);
            $table->decimal('risk', 8, 2);
            $table->decimal('capital', 10, 2);
            $table->integer('adx_threshold')->default(25);
            $table->integer('min_confidence')->default(60);
            $table->boolean('use_session')->default(false);
            $table->boolean('use_ai')->default(false);
            $table->integer('ai_min')->nullable();
            $table->boolean('use_struct_exit')->default(false);
            // Logic fingerprint — md5 of PriceActionService.php
            $table->string('logic_hash', 64);
            // Results
            $table->integer('signals_count');
            $table->integer('filled_count');
            $table->integer('win_count');
            $table->integer('loss_count');
            $table->integer('expired_count')->default(0);
            $table->integer('struct_exit_count')->default(0);
            $table->decimal('fill_rate', 5, 2);
            $table->decimal('winrate', 5, 2);
            $table->decimal('pnl', 10, 2);
            $table->decimal('capital_end', 10, 2);
            $table->integer('run_duration_ms')->nullable();
            $table->longText('signals_json')->nullable();
            $table->timestamps();

            $table->index(['symbol', 'timeframe', 'from_date', 'to_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backtest_runs');
    }
};
