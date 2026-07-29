import { defineConfig, devices } from '@playwright/test'

const fixtureUrl = 'http://127.0.0.1:4010'
const storefrontUrl = 'http://127.0.0.1:3000'

export default defineConfig({
  testDir: './test/browser',
  outputDir: './test-results',
  testIgnore: /commerce-release-states\.spec\.ts/,
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI
    ? [['line'], ['html', { open: 'never', outputFolder: 'playwright-report' }]]
    : [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]],
  use: {
    baseURL: storefrontUrl,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  webServer: [
    {
      command: 'node test/browser/fixtures/cart-api-server.mjs',
      url: `${fixtureUrl}/health`,
      reuseExistingServer: false,
      timeout: 30_000,
    },
    {
      command: 'node test/browser/fixtures/start-storefront.mjs approved',
      url: `${storefrontUrl}/cart`,
      reuseExistingServer: false,
      timeout: 60_000,
      env: {
        HOST: '127.0.0.1',
        PORT: '3000',
        NUXT_API_SERVER_BASE_URL: `${fixtureUrl}/api/v1`,
        NUXT_PUBLIC_API_BASE_URL: `${fixtureUrl}/api/v1`,
        NUXT_PUBLIC_SITE_URL: storefrontUrl,
        NUXT_PUBLIC_CART_COOKIE_SECURE: 'false',
        NUXT_PUBLIC_COMMERCE_ENABLED: 'true',
        NUXT_PUBLIC_COMMERCE_CONFIRMATION_ENABLED: 'true',
        NUXT_PUBLIC_LEGAL_CONTENT_APPROVED: 'true',
      },
    },
  ],
  projects: [
    {
      name: 'chromium-desktop',
      use: {
        ...devices['Desktop Chrome'],
        viewport: { width: 1440, height: 900 },
      },
      testIgnore: /cart-mobile\.spec\.ts/,
    },
    {
      name: 'webkit-mobile',
      use: {
        ...devices['iPhone 13'],
      },
      testMatch: /(?:cart-mobile|checkout-payment-acceptance)\.spec\.ts/,
    },
  ],
})
