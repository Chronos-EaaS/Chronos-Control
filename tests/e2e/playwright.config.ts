import { defineConfig, devices } from '@playwright/test';
import path from 'node:path';
import { reportRoot, resultsRoot } from './paths';

// This suite mutates only a fixed disposable loopback stack. A local Angular
// preview can proxy that same API; arbitrary environment URLs are rejected.
const baseURL = process.env.CHRONOS_V3_PREVIEW === '1' ? 'http://127.0.0.1:4200' : 'http://127.0.0.1:8089';
export default defineConfig({
  testDir: './v3', fullyParallel: false, workers: 1,
  forbidOnly: Boolean(process.env.CI), retries: 0, timeout: 45_000,
  // Allow the complete serialized five-browser matrix to finish on shared CI runners.
  globalTimeout: 45 * 60_000, expect: { timeout: 8_000 },
  outputDir: path.join(resultsRoot, 'v3'),
  reporter: [['list'], ['html', { open: 'never', outputFolder: path.join(reportRoot, 'v3') }], ['json', { outputFile: path.join(resultsRoot, 'v3/results.json') }]],
  use: { baseURL, locale: 'en-GB', timezoneId: 'Europe/Zurich', trace: 'retain-on-failure', screenshot: 'only-on-failure' },
  projects: [
    { name: 'desktop-chromium', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 1000 } } },
    { name: 'desktop-firefox', use: { ...devices['Desktop Firefox'], viewport: { width: 1440, height: 1000 } } },
    { name: 'desktop-webkit', use: { ...devices['Desktop Safari'], viewport: { width: 1440, height: 1000 } } },
    { name: 'mobile-chromium', use: { ...devices['Pixel 7'] } },
    { name: 'mobile-webkit', use: { ...devices['iPhone 13'] } },
  ],
});
