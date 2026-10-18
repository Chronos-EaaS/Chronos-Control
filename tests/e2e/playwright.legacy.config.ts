import { defineConfig, devices } from '@playwright/test';
import path from 'node:path';
import { reportRoot, resultsRoot } from './paths';

// This suite performs mutations and must never accept a production target.
const baseURL = 'http://127.0.0.1:8088';

export default defineConfig({
  testDir: '.',
  testMatch: ['legacy/**/*.spec.ts', 'http/**/*.spec.ts'],
  fullyParallel: false,
  workers: 1,
  forbidOnly: Boolean(process.env.CI),
  retries: 0,
  timeout: 30_000,
  globalTimeout: 30 * 60_000,
  expect: { timeout: 7_500 },
  outputDir: path.join(resultsRoot, 'legacy'),
  reporter: [
    ['list'],
    ['html', { open: 'never', outputFolder: path.join(reportRoot, 'legacy') }],
    ['json', { outputFile: path.join(resultsRoot, 'legacy/results.json') }],
  ],
  use: {
    baseURL,
    locale: 'en-GB',
    timezoneId: 'Europe/Zurich',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
  },
  projects: [
    { name: 'desktop-chromium', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 1000 } } },
    { name: 'desktop-firefox', testIgnore: '**/http/**', use: { ...devices['Desktop Firefox'], viewport: { width: 1440, height: 1000 } } },
    { name: 'desktop-webkit', testIgnore: '**/http/**', use: { ...devices['Desktop Safari'], viewport: { width: 1440, height: 1000 } } },
    { name: 'mobile-chromium', testIgnore: '**/http/**', use: { ...devices['Pixel 7'] } },
    { name: 'mobile-webkit', testIgnore: '**/http/**', use: { ...devices['iPhone 13'] } },
  ],
});
