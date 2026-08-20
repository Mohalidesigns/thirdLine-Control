# Deployment — Nigerian data plane

Phase 16.2 deliverable. This is the reference topology for a tenant whose
`data_residency` is `NG`, sized against the CBN data-localisation direction
effective 1 January 2027 (seeded as pack `CBN-DATALOC-2026`; verify the
circular before quoting it to a customer — R10).

## Control plane vs data plane

| Plane | What lives there | Where it may run |
|---|---|---|
| Control plane | Build artefacts, container registry, CI, licence issuing, shared *metadata only* (pack catalogue versions, release notes) | Anywhere |
| Data plane | Application servers, MySQL, Redis, evidence disk, snapshot disk, backup disk, queue workers, Ollama inference | **In-country only** |

Nothing in the data plane calls out except the integrations mapped in
`config/residency.php` (`endpoint_regions`) — the residency guard blocks an
endpoint mapped to a foreign country, and the cross-border transfer register
records anything that lawfully leaves (NDPA Part VIII basis required).

## Candidate facilities (verify current certifications during procurement)

- **Rack Centre (LGS1/LGS2, Lagos)** — carrier-neutral colocation.
- **MainOne / Equinix LG1 (Lekki)** — colocation + cloud on-ramps.
- **Galaxy Backbone (Abuja)** — government-preferred hosting.
- **Open Access Data Centres (OADC, Lagos)** — carrier-neutral colocation.

## Reference topology (single tenant, branch-per-client)

- 2× app nodes (PHP-FPM + Nginx), 1× MySQL 8 primary + replica, 1× Redis
  (sessions, cache, queues — run workers under **Laravel Horizon** when the
  queue connection is `redis`), 1× Ollama node (GPU optional).
- All disks in `config/filesystems.php` map to in-country storage; set the
  `RESIDENCY_DISK_*` variables to `NG` — the guard refuses writes otherwise.
- Backups: `php artisan atheris:backup` on the scheduler, to the disk named
  by `RESIDENCY_BACKUP_DISK` (in-country); restore drill monthly via
  `scripts/restore-drill.sh`.
- Regional key management: `APP_KEY` and disk encryption keys generated and
  held in-country (KMS or HSM at the facility). Never reuse a key across
  country planes.
- `/health` exposed to the monitoring VLAN only; `/up` for the load balancer.
- Log shipping: `LOG_CHANNEL=structured` → in-country log store.

## Environment

Production hardening, before anything else:

```
APP_ENV=production
APP_DEBUG=false     # never true in production — debug pages serve stack
                    # traces, file paths, SQL and configuration to any
                    # caller who triggers an exception (DEF-020)
```

```
RESIDENCY_DEFAULT_COUNTRY=NG
RESIDENCY_DISK_LOCAL=NG
RESIDENCY_DISK_PUBLIC=NG
RESIDENCY_DISK_S3=NG          # in-country S3-compatible object store
RESIDENCY_QUEUE_REDIS=NG
RESIDENCY_BACKUP_DISK=local
QUEUE_CONNECTION=redis
LOG_CHANNEL=structured
```
