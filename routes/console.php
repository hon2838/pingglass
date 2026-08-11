<?php

use Illuminate\Support\Facades\Schedule;

// Probe cycle runs every minute
Schedule::command('pingglass:probe-cycle')->everyMinute()->withoutOverlapping(2);

// Complete stale probe cycles every 2 minutes
Schedule::command('pingglass:complete-cycles')->everyTwoMinutes()->withoutOverlapping();

// Evaluate target staleness every 3 minutes
Schedule::command('pingglass:evaluate-staleness')->everyThreeMinutes()->withoutOverlapping();

// 5-minute rollups run every 5 minutes
Schedule::command('pingglass:aggregate-five-minute')->everyFiveMinutes()->withoutOverlapping();

// Hourly rollups run once per hour
Schedule::command('pingglass:aggregate-hourly')->hourly()->withoutOverlapping();

// Cleanup old data daily at 3am
Schedule::command('pingglass:cleanup')->dailyAt('03:00')->withoutOverlapping();
