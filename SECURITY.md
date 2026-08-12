# Security policy

## Reporting a vulnerability

Email **security@atheris.africa** with a description, reproduction steps and
impact. Do not open a public issue for a vulnerability.

- Acknowledgement within **2 business days**; triage within **5**.
- We ask for **90 days** of coordinated disclosure; we credit reporters who
  wish to be named.
- Testing must be against your own installation or our staging environment —
  never against a customer's production system. No data exfiltration, no
  denial of service, no social engineering.

## Platform posture (Phase 16.3)

- Session cookies: `Secure`, `HttpOnly`, `SameSite=Lax`; session ids rotate
  on login.
- MFA: TOTP with per-role enforcement and break-glass caps (Phase 7.2).
- Account lockout: 5 failed logins throttle per email+IP with exponential
  backoff (Breeze `LoginRequest`), audited.
- Security headers on every response: CSP, HSTS (behind TLS),
  X-Frame-Options DENY, nosniff, Referrer-Policy, Permissions-Policy.
- Dependency scanning in CI: `composer audit` and `npm audit` fail the build
  on known-vulnerable pins (.github/workflows/ci.yml).
- Secrets live in `.env` only, read through `config/` (R9). The audit trail
  redacts declared secret attributes.
- Data residency is enforced, not configured: see `config/residency.php` and
  `docs/deployment/`.
