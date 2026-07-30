import { spawn, type ChildProcessWithoutNullStreams } from 'node:child_process'
import { expect, test } from '@playwright/test'
import {
  captureUnexpectedBrowserErrors,
  fixtureState,
  fixtureUrl,
  resetFixture,
} from './helpers'

const ssrPort = 3010
const ssrStorefrontUrl = `http://127.0.0.1:${ssrPort}`
let ssrServer: ChildProcessWithoutNullStreams
let serverOutput = ''

async function waitForSsrServer() {
  for (let attempt = 0; attempt < 100; attempt += 1) {
    if (ssrServer.exitCode !== null) {
      throw new Error(`Focused SSR server exited early.\n${serverOutput}`)
    }

    try {
      const response = await fetch(`${ssrStorefrontUrl}/obshti-usloviya`)

      if (response.ok) {
        return
      }
    } catch {
      // The production server is still starting.
    }

    await new Promise(resolve => setTimeout(resolve, 100))
  }

  throw new Error(`Focused SSR server did not become ready.\n${serverOutput}`)
}

test.describe('open-state Cart and Checkout SSR API base selection', () => {
  test.beforeAll(async () => {
    ssrServer = spawn(process.execPath, ['.output/server/index.mjs'], {
      cwd: process.cwd(),
      env: {
        ...process.env,
        HOST: '127.0.0.1',
        PORT: String(ssrPort),
        NUXT_API_SERVER_BASE_URL: `${fixtureUrl}/api/v1`,
        NUXT_PUBLIC_API_BASE_URL: '/api/v1',
        NUXT_PUBLIC_SITE_URL: ssrStorefrontUrl,
        NUXT_PUBLIC_CART_COOKIE_SECURE: 'false',
        NUXT_PUBLIC_COMMERCE_ENABLED: 'true',
        NUXT_PUBLIC_COMMERCE_CONFIRMATION_ENABLED: 'true',
        NUXT_PUBLIC_LEGAL_CONTENT_APPROVED: 'true',
      },
      stdio: 'pipe',
    })
    ssrServer.stdout.on('data', chunk => serverOutput += chunk.toString())
    ssrServer.stderr.on('data', chunk => serverOutput += chunk.toString())

    await waitForSsrServer()
  })

  test.afterAll(async () => {
    if (ssrServer.exitCode === null) {
      ssrServer.kill()
      await Promise.race([
        new Promise(resolve => ssrServer.once('exit', resolve)),
        new Promise(resolve => setTimeout(resolve, 2_000)),
      ])
    }
  })

  test.beforeEach(async ({ request }) => {
    await resetFixture(request)
  })

  test('renders Cart, Checkout and confirmation without exposing the private API URL', async ({
    context,
    page,
    request,
  }) => {
    const browserErrors = captureUnexpectedBrowserErrors(page)

    await page.route(`${ssrStorefrontUrl}/api/v1/**`, async (route) => {
      const browserRequest = route.request()
      const sourceUrl = new URL(browserRequest.url())
      const headers = Object.fromEntries(
        Object.entries(browserRequest.headers())
          .filter(([name]) => !['host', 'origin', 'referer', 'content-length'].includes(name.toLowerCase())),
      )
      const upstream = await request.fetch(
        `${fixtureUrl}${sourceUrl.pathname}${sourceUrl.search}`,
        {
          method: browserRequest.method(),
          headers,
          data: browserRequest.postDataBuffer() ?? undefined,
        },
      )

      await route.fulfill({
        status: upstream.status(),
        headers: upstream.headers(),
        body: await upstream.body(),
      })
    })

    const cartResponse = await page.goto(`${ssrStorefrontUrl}/cart`)
    expect(cartResponse?.status()).toBe(200)
    await expect(page.getByText('Количката е празна')).toBeVisible()
    await page.waitForTimeout(100)

    const html = await page.content()
    expect(html).toContain('apiBaseUrl:"/api/v1"')
    expect(html).not.toContain(fixtureUrl)

    const cartCookie = (await context.cookies(ssrStorefrontUrl))
      .find(cookie => cookie.name === 'mc_cart_session')
    expect(cartCookie?.value).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-8[0-9a-f]{3}-[0-9a-f]{12}$/)

    const checkoutResponse = await page.goto(`${ssrStorefrontUrl}/checkout`)
    expect(checkoutResponse?.status()).toBe(200)
    await expect(page.getByText('Количката е празна')).toBeVisible()
    await page.waitForTimeout(100)

    const successResponse = await page.goto(`${ssrStorefrontUrl}/checkout/success`)
    expect(successResponse?.status()).toBe(200)
    await page.waitForTimeout(100)

    expect(browserErrors).toEqual([])

    const state = await fixtureState(request)
    const prohibitedRequests = state.requests.filter(item => (
      item.path === '/api/v1/checkout'
      || (
        item.path.startsWith('/api/v1/cart')
        && ['POST', 'PATCH', 'DELETE'].includes(item.method)
      )
    ))

    expect(state.requests.filter(item => item.path === '/api/v1/cart').length)
      .toBeGreaterThanOrEqual(2)
    expect(prohibitedRequests).toEqual([])
    expect(state.orders_created).toBe(0)
    expect(state.payment_attempts).toBe(0)
    expect(state.payment_transactions).toBe(0)
    expect(state.provider_invocations).toBe(0)
  })
})
