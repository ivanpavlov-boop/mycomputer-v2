import { expect, test } from '@playwright/test'
import {
  captureUnexpectedBrowserErrors,
  fixtureState,
  resetFixture,
} from './helpers'

const acceptedCapability = 'A'.repeat(43)
const unavailableCapability = 'B'.repeat(43)

test.setTimeout(30_000)

test.beforeEach(async ({ request }) => {
  await resetFixture(request, { preset: 'product' })
})

test('clears the fragment before body-only recovery and redirects safely', async ({ page, request }) => {
  const browserErrors = captureUnexpectedBrowserErrors(page)
  const requests: Array<{
    url: string
    referrer: string | undefined
    body: unknown
    pageUrl: string
  }> = []

  page.on('request', (browserRequest) => {
    let body: unknown = null

    if (browserRequest.url().endsWith('/api/v1/cart/recover')) {
      body = browserRequest.postDataJSON()
    }

    requests.push({
      url: browserRequest.url(),
      referrer: browserRequest.headers().referer,
      body,
      pageUrl: page.url(),
    })
  })

  const response = await page.goto(`/cart/recover#${acceptedCapability}`)
  const serverHtml = await response?.text()

  expect(response?.status()).toBe(200)
  expect(response?.url()).toBe('http://127.0.0.1:3000/cart/recover')
  expect(serverHtml).not.toContain(acceptedCapability)
  await expect(page).toHaveURL(/\/cart$/)

  const recoveryRequests = requests.filter(entry => entry.url.endsWith('/api/v1/cart/recover'))
  expect(recoveryRequests).toHaveLength(1)
  expect(recoveryRequests[0]?.body).toEqual({ capability: acceptedCapability })
  expect(recoveryRequests[0]?.pageUrl).toBe('http://127.0.0.1:3000/cart/recover')

  for (const entry of requests) {
    expect(entry.url).not.toContain(acceptedCapability)
    expect(entry.referrer ?? '').not.toContain(acceptedCapability)
  }

  const browserState = await page.evaluate(() => ({
    html: document.documentElement.outerHTML,
    local: JSON.stringify(localStorage),
    session: JSON.stringify(sessionStorage),
    cookies: document.cookie,
    nuxtPayload: JSON.stringify((window as typeof window & { __NUXT__?: unknown }).__NUXT__),
  }))

  expect(JSON.stringify(browserState)).not.toContain(acceptedCapability)
  expect(JSON.stringify(await fixtureState(request))).not.toContain(acceptedCapability)
  expect(browserErrors).toEqual([])
})

test('uses one neutral state for an unavailable recovery capability', async ({ page }) => {
  const unavailable = await page.goto(`/cart/recover#${unavailableCapability}`)

  expect(unavailable?.status()).toBe(200)
  await expect(page).toHaveURL(/\/cart\/recover$/)
  await expect(page.getByText('Линкът за възстановяване не е наличен или е изтекъл.')).toBeVisible()
  expect(await page.content()).not.toContain(unavailableCapability)
})

test('uses one neutral state for a malformed recovery fragment', async ({ page }) => {
  const malformed = await page.goto('/cart/recover#not-valid')

  expect(malformed?.status()).toBe(200)
  await expect(page).toHaveURL(/\/cart\/recover$/)
  await expect(page.getByText('Линкът за възстановяване не е наличен или е изтекъл.')).toBeVisible()
})
