# End-to-end tests

Real browser (Chromium via Playwright) against the real backend. They walk through the working
day of the laboratory: first-time setup, staff accounts and roles, verification of reference ranges,
and the full report flow (register patient -> tests -> results incl. calculated values -> finalize ->
print preview, with the frozen snapshot check).

The tests run in order and share one database, so start from a FRESH one.

```bash
# 1. backend on :8000 with an empty SQLite database
cd backend && cp .env.example .env && php artisan key:generate
touch /tmp/e2e.sqlite
sed -i 's|^DB_CONNECTION=.*|DB_CONNECTION=sqlite|; s|^DB_DATABASE=.*|DB_DATABASE=/tmp/e2e.sqlite|' .env
php artisan migrate:fresh --seed --force && php artisan serve --host=127.0.0.1 --port=8000

# 2. built frontend on :4173 (proxies /api to :8000)
cd frontend && npm ci && npm run build && npx vite preview --host 127.0.0.1 --port 4173

# 3. the tests
cd e2e && npm ci && npx playwright install chromium && npx playwright test
```

GitHub Actions runs exactly this in the `e2e` job of `.github/workflows/ci.yml`.
