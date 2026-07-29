import { defineConfig, devices } from '@playwright/test'

const fixtureUrl = 'http://127.0.0.1:4010'
const state = process.env.COMMERCE_TEST_STATE

if (state !== 'closed' && state !== 'confirmation_only') {
  throw new Error('COMMERCE_TEST_STATE must be closed or confirmation_only')
}

const storefrontPort = state === 'closed' ? 3001 : 3002
const storefrontUrl = `http://127.0.0.1:${storefrontPort}`

export default defineConfig({
  testDir: './test/browser',
  testMatch: /commerce-release-states\.spec\.ts/,
  outputDir: './test-results',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['line']],
  use: {
    baseURL: storefrontUrl,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  webServer: [
    {
      command: 'node test/browser/fixtures/cart-api-server.mjs',
      url: `${fixtureUrl}/health`,
      reuseExistingServer: false,
      timeout: 30_000,
    },
    {
      command: 'node .output/server/index.mjs',
      url: `${storefrontUrl}/catalog`,
      reuseExistingServer: false,
      timeout: 60_000,
      env: {
        HOST: '127.0.0.1',
        PORT: String(storefrontPort),
        NUXT_API_SERVER_BASE_URL: `${fixtureUrl}/api/v1`,
        NUXT_PUBLIC_API_BASE_URL: `${fixtureUrl}/api/v1`,
        NUXT_PUBLIC_SITE_URL: storefrontUrl,
        NUXT_PUBLIC_CART_COOKIE_SECURE: 'false',
        NUXT_PUBLIC_COMMERCE_ENABLED: 'false',
        NUXT_PUBLIC_COMMERCE_CONFIRMATION_ENABLED: state === 'confirmation_only'
          ? 'true'
          : 'false',
      },
    },
  ],
  projects: [{
    name: `chromium-${state}`,
    use: {
      ...devices['Desktop Chrome'],
      viewport: { width: 1440, height: 900 },
    },
  }],
})
