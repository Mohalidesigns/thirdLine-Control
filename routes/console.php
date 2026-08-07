<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('secondline:generate-test-instances')->dailyAt('01:00');
Schedule::command('secondline:refresh-ageing')->dailyAt('01:15');
Schedule::command('secondline:expire-compensating-controls')->dailyAt('01:30');
Schedule::command('secondline:run-escalations')->dailyAt('07:00');
Schedule::command('secondline:send-owner-digests')->weeklyOn(1, '07:30');
Schedule::command('secondline:queue-evidence-disposal')->dailyAt('02:00');
