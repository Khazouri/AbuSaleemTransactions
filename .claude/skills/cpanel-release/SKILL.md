---
name: cpanel-release
description: Use when deploying or releasing this app to the cPanel shared host (no SSH), or when a deployed site shows a black screen, CORS error, 404 on refresh, or ignores an .env change. Walks the steps in frontend/DEPLOYMENT.md.
---

# Release to cPanel

`frontend/DEPLOYMENT.md` is the source of truth; read the relevant section before acting.

1. Backend: `composer install --no-dev --optimize-autoloader` locally. `vendor/` must be uploaded; without it Laravel does not boot.
2. SPA: set `frontend/.env.production` (`VITE_API_BASE_URL` is baked in at build time), `npm run build`, upload only the contents of `frontend/dist/`, including `.htaccess`.
3. Server `.env`: `FRONTEND_URL` must equal the SPA origin exactly (scheme + host, no trailing slash). First install also needs `MAINTENANCE_BOOTSTRAP_TOKEN` (24+ chars).
4. First install: open `/api/maintenance/bootstrap?token=...` in a browser and press the button; then in `/maintenance` run Clear all caches → Optimise for production, and remove the token from `.env`.
5. Every release: upload, then in the Maintenance console (R08) run Run migrations → Clear all caches → Optimise for production.
6. Check against "Checks when something looks wrong": black screen = source tree uploaded instead of dist; 404 on refresh = missing `.htaccess`; CORS error = `FRONTEND_URL`; `.env` change ignored = delete `bootstrap/cache/config.php`.
