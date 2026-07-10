# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| main    | ✅        |

## Reporting a Vulnerability

**Do not open a public GitHub issue for security vulnerabilities.**

Email **support@itcc.co** with:

- A description of the vulnerability and its impact.
- Steps to reproduce (proof of concept if possible).
- Affected component(s) and version/commit.

You will receive an acknowledgement within **48 hours** and a remediation timeline within **5 business days**. We ask that you give us a reasonable window to release a fix before public disclosure.

## Security Practices in This Project

- **Tenant isolation:** database-per-tenant; no cross-tenant queries by construction.
- **Auth:** Sanctum tokens, hashed passwords (bcrypt), optional TOTP 2FA, login history & failed-login lockout.
- **Input:** validated via Form Requests; output escaped; Eloquent parameter binding prevents SQL injection.
- **Transport:** HTTPS enforced in production; secure, http-only cookies for session/refresh where applicable.
- **Secrets:** never committed; provided via environment variables.
- **Auditing:** sensitive actions recorded in an immutable audit log.
- **Dependencies:** scanned in CI; keep `composer.lock` / `package-lock.json` current.
