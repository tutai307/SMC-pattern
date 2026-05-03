<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Kiểm tra lệnh đang mở mỗi phút, gửi cảnh báo Telegram nếu cần
Schedule::command('signals:monitor')->everyMinute()->withoutOverlapping();
