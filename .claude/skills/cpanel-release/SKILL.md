---
name: cpanel-release
description: Prepare and ship a release of this app to the cPanel shared-hosting production (no SSH) - pre-flight checks, SPA build with the production API URL, vendor/ upload, git-ftp push, then the maintenance-console steps and post-release checks. Use when the user says "deploy", "release", "upload to the server", "push to cPanel/production", or asks why production broke after an upload.
---

# Release to cPanel

Production is cPanel shared hosting with **no shell**. `frontend/DEPLOYMENT.md` is the full
reference; this skill is the ordered procedure plus the traps. Nothing in it is run on the
server except through the browser (maintenance console).

## 0. Decide with the user first

- **What is being released** — the commit range since the last deploy (`git log` against
  the last deployed commit; git-ftp stores it on the server in `.git-ftp.log`).
- **Does it include migrations?** `git diff --name-only <last>..HEAD -- database/migrations`
- **Does it add a screen or change a grant?** If `ScreenSeeder` or
  `ScreenRolePermissionSeeder` changed, **stop and raise it**. The console's
  "Re-seed reference data" (`db:seed`) resets the whole permission matrix to its defaults
  and discards customisations made in Roles & Permissions. Don't run it on production
  unless the user confirms nothing there was customised (see OPEN_ITEMS.md).
- **Did `composer.lock` change?** Then `vendor/` must be re-uploaded (step 2).

## 1. Pre-flight (local)

1. Working tree clean; the release commit has passed `verify-and-commit`
   (full PHPUnit, Pint, build, locale parity).
2. `frontend/.env.production` has the **https** production API URL in
   `VITE_API_BASE_URL`. It is git-ignored and baked in at build time — editing it on the
   server does nothing.

## 2. Build locally

```bash
composer install --no-dev --optimize-autoloader   # only if composer.lock changed; restore dev deps after
cd frontend && npm ci && npm run build            # produces frontend/dist/
```

Check that `frontend/dist/.htaccess` exists: without it, a refresh on any sub-route 404s.
`frontend/dist` is tracked in git, so commit the build as its own commit
(`Build SPA for release <date>`). That commit is the deliverable, so **don't** revert dist
here.

Then restore dev dependencies locally: `composer install`.

## 3. Upload

- **git-ftp** uploads **committed** files that changed since the last deploy, minus
  `.git-ftp-ignore` (shell-glob patterns — `dir/*`, not `dir/.*`). Run `git ftp push`.
- **`vendor/` is git-ignored, so git-ftp never uploads it.** If `composer.lock` changed,
  upload `vendor/` separately (cPanel File Manager zip + extract, or an FTP client). Without
  it Laravel won't boot, and neither will the maintenance console.
- **SPA:** the `dist/` *contents* go in the SPA subdomain's docroot (DEPLOYMENT.md, Deploy).

## 4. Maintenance console (browser, as R08) — `/maintenance`

Every release after the first:
1. Check the diagnostics panel (pending migrations, DB connection, writable paths).
2. **Run migrations** — only if the release has any.
3. **Clear all caches**, then **Optimise for production**.

The first install only: use the bootstrap URL with `MAINTENANCE_BOOTSTRAP_TOKEN`
(DEPLOYMENT.md, "Bootstrap, once"), then remove the token from `.env`.

## 5. Post-release checks

Walk through the table under "Checks when something looks wrong" in DEPLOYMENT.md:
- Login works: CORS means `FRONTEND_URL`; mixed content means `VITE_API_BASE_URL`.
- Refresh on a sub-route works (`.htaccess`).
- A screen from this release opens for a role that should have it.

An `.env` change with no effect means a cached config: delete `bootstrap/cache/config.php`
in File Manager, or run **Clear all caches**.

## 6. Record

Add an `AGENT_NOTES.md` entry: which commit was deployed, whether migrations ran, and
anything that went wrong and how it was fixed. Use a real commit subject, not "test" or
"upload".
