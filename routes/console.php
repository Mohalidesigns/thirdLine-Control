<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('secondline:generate-test-instances')->dailyAt('01:00');
Schedule::command('secondline:refresh-ageing')->dailyAt('01:15');
Schedule::command('secondline:expire-compensating-controls')->dailyAt('01:30');
Schedule::command('secondline:run-escalations')->dailyAt('07:00');
Schedule::command('secondline:send-owner-digests')->weeklyOn(1, '07:30');
Schedule::command('secondline:queue-evidence-disposal')->dailyAt('02:00');

// Phase 8 — regulatory obligation engine
Schedule::command('atheris:generate-obligation-instances')->dailyAt('02:30');
Schedule::command('atheris:poll-regulatory-feeds')->dailyAt('06:00');

// Phase 9 — control library v2
Schedule::command('atheris:remind-document-reviews')->weeklyOn(1, '08:00');

// Phase 10 — risk management v2. Posture first (treatments and appetite),
// then the KRI engine, so a breach opened this morning escalates on the
// same run as the escalation sweep at 07:00.
Schedule::command('atheris:refresh-risk-posture')->dailyAt('03:00');
Schedule::command('atheris:evaluate-metrics')->dailyAt('03:30');

// Phase 11 — governance clocks. Hourly, not daily: a 24-hour complaint
// acknowledgement window and a 72-hour breach notification cannot be
// managed by a job that runs once a night.
Schedule::command('atheris:refresh-governance-clocks')->hourly();
