import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright configuration for Research Services E2E suite.
 *
 * BASE_URL is injected by docker-compose.e2e.yml (http://nginx inside Docker)
 * or can be set on the host for local headed runs against a running instance.
 */
const baseURL = process.env.BASE_URL ?? 'http://localhost';

export default defineConfig({
  testDir: './tests',
  outputDir: './test-results',

  // Fail fast in CI; keep going in dev
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,

  reporter: [
    ['list'],
    ['html', { outputFolder: './test-results/html-report', open: 'never' }],
  ],

  use: {
    baseURL,

    // Capture evidence on every test — satisfies the visual-verification gate
    screenshot: 'on',
    video: 'retain-on-failure',
    trace: 'retain-on-failure',

    // Livewire polling can be slow inside Docker; give it headroom
    actionTimeout: 15_000,
    navigationTimeout: 30_000,
  },

  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
