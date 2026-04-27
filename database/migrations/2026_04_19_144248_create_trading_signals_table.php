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
        Schema::create('trading_signals', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->string('timeframe')->nullable();
            $table->enum('type', ['LONG', 'SHORT']);
            $table->decimal('entry_price', 18, 8);
            $table->decimal('tp_price', 18, 8);
            $table->decimal('sl_price', 18, 8);
            $table->integer('winrate')->nullable();
            $table->string('status')->default('PENDING'); // PENDING, WIN, LOSS, CANCELLED
            $table->text('reason')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trading_signals');
    }
};
