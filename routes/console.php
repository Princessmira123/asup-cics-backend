<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Runs daily — checks every active loan's next_payment_date and collects
// that cycle's installment from savings if one is due. See
// app/Console/Commands/ProcessLoanDeductions.php for the actual logic.
// NOTE: this only fires automatically if something is actually calling
// `php artisan schedule:run` every minute (or `schedule:work` is running
// continuously) on the server — a bare deploy alone does not do that. On
// Railway this typically means either a Cron Job hitting `schedule:run`,
// or a second service process running `php artisan schedule:work`.
Schedule::command('loans:process-deductions')->daily();
