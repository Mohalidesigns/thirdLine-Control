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

// Phase 12 — continuous controls monitoring. Extract first, evaluate
// second, so a rule always reads the night's data rather than yesterday's;
// both run before the 07:00 escalation sweep, so an exception raised by a
// rule at 04:30 escalates on the same morning. Purge last, after the runs
// that might have needed the snapshot.
Schedule::command('atheris:sync-data-sources')->dailyAt('04:00')->withoutOverlapping();
Schedule::command('atheris:run-monitoring-rules --no-capture')->dailyAt('04:30')->withoutOverlapping();
Schedule::command('atheris:purge-snapshots')->dailyAt('05:30');

// Phase 13 — scheduled reporting. Every fifteen minutes rather than nightly,
// because a schedule carries its own cron and its own timezone: a board pack
// due at 06:45 Lagos and a group return due at 06:45 London are different
// moments, and a once-a-night sweep would file both late.
Schedule::command('reports:run-scheduled')->everyFifteenMinutes()->withoutOverlapping();

// Phase 14 — AI knowledge index. The queued listener handles the normal
// path; this sweep is the backstop for a queue outage and runs before the
// working day so the first Atlas question of the morning is answered from
// current records. Raw model output ages out weekly under its retention
// window, leaving the interaction record itself intact (R3).
Schedule::command('ai:reindex')->dailyAt('05:45')->withoutOverlapping();
Schedule::command('ai:prune')->weeklyOn(7, '03:45');
