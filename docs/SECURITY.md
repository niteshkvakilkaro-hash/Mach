# Security review — 18 Sep 2026

Scope: whole codebase (public site, admin CRM, technician app, APIs, uploads, config).
Method: code search for every risky pattern + live attacks against the local install.

## Result

No exploitable vulnerability found in authentication, authorization, CSRF, XSS, SQL or uploads.
One bug and several hardening gaps were fixed (below).

| Area | How it is protected | Live test |
|---|---|---|
| Authorization | Every admin page / API calls `require_permission()`; leads use row-level `lead_visibility()`; technicians see only their own jobs | Sales user: other leads → 404, admin pages → 403, other user's notification untouched |
| CSRF | Token on every form + AJAX (`csrf_verify`, `hash_equals`); logout is POST-only; SameSite=Lax cookie | Cross-site POST without token → rejected |
| XSS | All output through `e()`; JSON via `js_json()` (hex-escaped); JS uses `textContent` | `<script>`, `"><img onerror>` in every lead field → rendered as text on all admin pages |
| SQL injection | Prepared statements only; identifiers/ORDER BY come from code whitelists | Quote, UNION, `OR 1=1`, `SLEEP(3)` on 13 filters → no effect, no delay |
| Uploads | MIME + decode check, GD re-encode, random names, 2 MB cap, PHP disabled in `/uploads` | `.php/.PHP/.phtml/.phar/…` in uploads → 403; fake image → rejected |
| Sessions | HttpOnly, SameSite, Secure on HTTPS, strict mode, id regenerated on login, 2 h idle timeout, inactive users signed out | Fixation, forged id, reuse after logout → all fail |
| Login | bcrypt, 5 failures / 15 min per email (15 per IP), timing-safe for unknown emails, safe post-login redirect | `//evil.com`, `https://evil.com`, `javascript:` redirects → stay on site |
| Private files | `.htaccess` denies config, includes, database, storage, docs, cron, tools, `.sql/.log/.md/.zip`, dotfiles | All → 403 |
| Exports | CSV formula injection neutralised | — |
| Audit | Every create/update/delete/assign/login/denied action logged with before/after | — |

## Fixed in this review

1. **Rewrite loop → HTTP 500** on odd URLs such as `/services/AC-REPAIR` (10 internal redirects each). Extensionless rule now only fires when the exact `.php` file exists → clean 404.
2. **Debug mode default** — `config/app.php` now defaults to `production` on any host except localhost/CLI, so a forgotten setting can't leak stack traces.
3. **Headers** — added `Content-Security-Policy` (frame-ancestors, object-src, base-uri, form-action), `Cross-Origin-Opener-Policy`; static files now get `nosniff` / `X-Frame-Options`; uploads served with a sandboxing CSP.
4. **Request size** — `LimitRequestBody 10 MB` (was unlimited up to PHP's 256 MB).
5. **Uploads block** — now case-insensitive (matters on Linux hosts).
6. **Default admin password** — red banner in the admin until `Admin@12345` is changed.
7. (Found earlier in Phase 9) report grouping alias clash — fixed.

## Accepted / by design

- Staff with `bookings.view` see all bookings (row-level limits apply to leads only).
- Technicians enter quoted/final amounts themselves; every change is in booking history + audit log.
- Login lockout per email can be triggered by someone who knows a staff email (5 tries → 15 min wait).
- No self-service "forgot password": a Super Admin resets it from Staff (avoids email-based takeover until SMTP is set up).

## Must do at deployment (Phase 10)

- HTTPS only (Secure cookies + HSTS switch on automatically).
- Change the seed admin password and email; remove demo data.
- Hosting must honour `.htaccess` (Apache/LiteSpeed). On Nginx, replicate the deny rules.
- Real DB user with only the privileges the app needs (not `root`), strong password.
- If behind a proxy/CDN, update `client_ip()` for rate limiting.
- Set PHP `upload_max_filesize` ≈ 4M, `post_max_size` ≈ 10M, `expose_php=Off`.
- Daily database backup.
