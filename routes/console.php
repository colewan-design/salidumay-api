<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('anime:scrape --pages=4')->hourly();
Schedule::command('manhwa:scrape --pages=4')->hourly();
Schedule::command('anime:enrich-anilist')->daily();
Schedule::command('tv:scrape --pages=3 --detail --seasons')->daily();
Schedule::command('films:scrape --pages=3 --detail')->daily();
