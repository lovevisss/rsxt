<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('personnel:refresh-grade-levels')->dailyAt('00:05')->timezone('Asia/Shanghai')->withoutOverlapping();
Schedule::command('personnel:refresh-departments')->dailyAt('00:10')->timezone('Asia/Shanghai')->withoutOverlapping();
