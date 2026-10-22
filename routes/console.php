<?php

use App\Support\Operations\SchedulerHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(static function (): void {
    app(SchedulerHeartbeat::class)->record();
})
    ->name('system:scheduler-heartbeat')
    ->everyMinute()
    ->withoutOverlapping();
