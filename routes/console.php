<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// signals:monitor chạy như daemon qua supervisord (không dùng scheduler)

// Gửi báo cáo xu hướng mỗi giờ cho XAGUSDT, XAUUSDT, BTCUSDT
Schedule::command('trend:hourly')->hourly();
