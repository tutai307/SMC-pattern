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
            $table->decimal('capital', 10, 2)->nullable()->after('reason');
            $table->timestamp('filled_at')->nullable()->after('capital')
                  ->comment('null = chưa khớp lệnh, có giá trị = đã vào lệnh thật');
        });
    }

    public function down(): void
    {
        Schema::table('trading_signals', function (Blueprint $table) {
            $table->dropColumn(['capital', 'filled_at']);
        });
    }
};
