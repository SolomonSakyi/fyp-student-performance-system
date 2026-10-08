# S20 — Security Hardening Audit

**Session:** S20
**Decisions:** SH1C, SH2A, SH3A, SH4A, SH5C, SH6B, SH7A, SH8A, SH9C, SH10A, SH11A, SH12B
**Scope:** Existing pages and endpoints only. No new features. No schema changes.

## What shipped in this session

1. `app/bootstrap.php` — single include for every page and endpoint.
2. `app/helpers/Security.php` — session hardening, CSRF, headers, HTTPS, `h()`, `h_js()`, guards.
3. `public/.htaccess` + `public/web.config` — deny direct web access to sensitive paths.
4. Per-page edits (see "Page-by-page changes" below): swap the inline
   `session_start()` + auth block for `require_once app/bootstrap.php`,
   insert `<?= csrf_field() ?>` in every `<form method="POST">`,
   call `verify_csrf()` at the top of every POST branch, and confirm
   `tenant_id` scoping and `deleted_at IS NULL` on every multi-tenant query.

## Deferred to a dedicated auth-hardening session

- Login throttling (SH9C) — no `login_attempts` table added in S20.
- Password reset / change flow — not part of S20.
- Login page itself — not part of S20.
- Strict CSP without `'unsafe-inline'` — requires externalising inline scripts/styles, which is its own pass.
- PII scrubbing in logs (SH10B) — target for a follow-up; S20 only stops logging raw POST bodies.

## Page-by-page changes

Each page listed below was updated to:

- Replace the top-of-file block:

```php
if (session_status() == PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/tenant/login.php');
    exit;
}