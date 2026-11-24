import { defineConfig } from '@playwright/test'

// Expects the backend on :8000 (fresh SQLite database, migrated + seeded) and the built frontend
// served by `vite preview` on :4173 (it proxies /api to the backend). See e2e/README.md.
export default defineConfig({
  testDir: './tests',
  timeout: 90_000,
  expect: { timeout: 15_000 },
  retries: 0,
  workers: 1,
  reporter: [['list']],
  use: {
    baseURL: 'http://127.0.0.1:4173',
    trace: 'off',
    screenshot: 'off',
    video: 'off',
  },
  projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
})
