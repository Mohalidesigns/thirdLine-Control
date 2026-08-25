# Control task partitioning and retention (CR-03 §C.7)

The departmental checklist writes far more rows than anything else in the
platform. At the client's own numbers:

| Branches | Daily tasks/day | Daily line results/day | Line results/year (all frequencies) |
|---|---|---|---|
| 50 | ~1,800 | ~20,400 | ~5.6 m |
| 100 | ~3,600 | ~40,800 | ~11.2 m |
| 250 | ~9,000 | ~102,000 | ~28 m |

Head Office adds a fixed ~52 tasks and ~474 line results a day on top,
whatever the branch count.

Three mitigations. Only the first is an operational step; the other two
already ship.

## 1. Partition by year — a MySQL deployment step, not a migration

`test_instances.period_year` is written by the model's `saving` hook on
every row (see `App\Models\TestInstance::booted()`), and both it and
`check_results` are indexed for the current-year working set.

Partitioning itself is **not** in a migration, deliberately. MySQL
`RANGE` partitioning has requirements the platform's schema does not meet
out of the box — every unique key must contain the partition column, and
foreign keys are not supported on partitioned tables — so turning it on
is a decision about a specific deployment's constraints, made once, by
someone looking at that database. Putting it in a migration would also
break the SQLite test suite, which has no partitioning at all.

Enable it when the branch count justifies it (§G.4 — confirm the number
with the client first). Sketch, to be adapted to the deployment:

```sql
-- Rehearse on a production-shaped dump first. This rewrites the table.
ALTER TABLE test_instances
  DROP FOREIGN KEY test_instances_control_id_foreign,
  /* …drop every FK; MySQL does not support them on partitioned tables… */
  DROP INDEX test_instances_scope_period_unique,
  ADD UNIQUE KEY test_instances_scope_period_unique
      (control_id, scope_key, period_label, frequency_id, period_year),
  PARTITION BY RANGE (period_year) (
    PARTITION p2026 VALUES LESS THAN (2027),
    PARTITION p2027 VALUES LESS THAN (2028),
    PARTITION pmax  VALUES LESS THAN MAXVALUE
  );
```

Note what the unique key becomes: `period_year` has to join it, and it is
functionally dependent on `period_label` anyway, so idempotency is
unaffected.

Add next year's partition before December. A `pmax` catch-all means a
missed year degrades to a slow partition rather than a failed insert.

## 2. Optional check items

Roughly 15% of the branch lines are guidance rather than a test
("Observe the branch ambience"). The importer already marks
continuous/observation lines `is_mandatory = false`
(`ControlFunctionImportService::syncItems()`), so submission does not
require a written result on each one and the write volume drops without
weakening evidence on the lines that matter.

## 3. Retention

`RetentionPolicySeeder` ships a **Control Task Line Evidence** policy at
24 months, disposal action Delete, dual approval required. It is a
placeholder: how long line-level daily evidence must survive is a
compliance answer governed by CBN and the bank's own records policy, not
an engineering one (§G.6). Confirm it with the client's DPO before the
first disposal run — `atheris:queue-evidence-disposal` acts on it
nightly.

## Rehearsing a generation run at scale

```bash
php artisan atheris:generate-control-tasks --dry-run
```

The dry run walks the same code path, resolves the same assignees and
reports the same counts, but writes nothing. Use it to size the window
before enabling the `control-functions` flag for a tenant.
