<?php

use App\Jobs\CheckCustomerInvoiceDeadlines;
use App\Jobs\CheckStockAlerts;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new CheckCustomerInvoiceDeadlines)->daily()->withoutOverlapping();
Schedule::job(new CheckStockAlerts)->hourly()->withoutOverlapping();

Schedule::command('backup:create')->dailyAt(config('backup.schedule_time', '02:00'))->withoutOverlapping();

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
