# Disaster recovery runbook

Phase 16.3 deliverable. Objectives (verify against the customer contract —
these are the platform defaults):

| Objective | Target | Measured by |
|---|---|---|
| RPO | ≤ 24h (daily backup) — ≤ 15min with binlog shipping enabled | age of newest backup at failure time |
| RTO | ≤ 4h | `scripts/restore-drill.sh` timing, recorded monthly |

## Backup chain

- `php artisan atheris:backup` runs daily via the scheduler, writing to the
  residency-guarded backup disk (`RESIDENCY_BACKUP_DISK`), retaining 14.
- The dump includes routines; evidence files live on the evidence disk and
  are backed up by the facility's snapshot schedule (verify at onboarding).
- The guard refuses a backup disk mapped outside the tenant's country — a
  misconfigured backup fails loudly rather than leaking (16.2).

## Restore drill (monthly, required)

```bash
scripts/restore-drill.sh
```

Takes a fresh backup, restores into a scratch database, verifies row counts
in `tenants`, `users`, `controls`, `control_exceptions`, `audit_trails`
(an empty audit trail fails the drill — R3), drops the scratch database and
prints the measured time. Record the timing here:

| Date | Duration | Backup size | Operator | Notes |
|---|---|---|---|---|
| _fill on each drill_ | | | | |

## Full recovery procedure

1. **Declare** — on-call declares DR, notes the time (RTO clock starts).
2. **Provision** — stand up the reference topology (docs/deployment/) in the
   same country plane. Never fail over across countries: residency survives
   DR.
3. **Restore** — latest dump via the drill script's restore steps; evidence
   disk from facility snapshot.
4. **Verify** — `php artisan migrate:status` (no pending), `/health` green,
   row counts vs the last drill record, one test login per role.
5. **Re-point** — DNS/ingress to the new plane; keep the old plane isolated
   for forensics.
6. **Report** — the incident goes into Atheris itself (incident module) with
   the timeline; the audit trail of the outage window is the gap record.

## Queue recovery

Jobs are idempotent by design (idempotency keys on integration payloads).
After restore: `php artisan queue:flush` for the dead letters recorded
before the failure, then let the scheduler re-generate instances; escalation
clocks recompute from the restored state.
