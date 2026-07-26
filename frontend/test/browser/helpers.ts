import { expect, type APIRequestContext, type BrowserContext, type Locator, type Page } from '@playwright/test'

export const fixtureUrl = 'http://127.0.0.1:4010'
export const storefrontUrl = 'http://127.0.0.1:3000'
export const seededSession = '11111111-1111-4111-8111-111111111111'

export async function resetFixture(
  request: APIRequestContext,
  scenario: Record<string, unknown> = {},
) {
  const reset = await request.post(`${fixtureUrl}/__test/reset`)
  expect(reset.ok()).toBe(true)

  if (Object.keys(scenario).length) {
    const configured = await request.post(`${fixtureUrl}/__test/scenario`, {
      data: scenario,
    })
    expect(configured.ok()).toBe(true)
  }
}

export async function configureFixture(
  request: APIRequestContext,
  scenario: Record<string, unknown>,
) {
  const response = await request.post(`${fixtureUrl}/__test/scenario`, {
    data: scenario,
  })
  expect(response.ok()).toBe(true)
}

export async function fixtureState(request: APIRequestContext) {
  const response = await request.get(`${fixtureUrl}/__test/state`)
  expect(response.ok()).toBe(true)

  return (await response.json()).data as {
    sessions: Array<{
      cart: Record<string, unknown> & {
        cart_session_id: string
        items: Array<Record<string, unknown>>
        bundle_items: Array<Record<string, unknown>>
      }
      owner: string | null
    }>
    requests: Array<{
      method: string
      path: string
      cart_session: string | null
      authorization_present: boolean
      origin: string | null
    }>
    analytics: Array<{
      event_name: string
      source: string
      payload: Record<string, unknown>
    }>
    orders_created: number
    payment_attempts: number
    confirmation_capabilities: number
  }
}

export async function setCartCookie(
  context: BrowserContext,
  value = seededSession,
) {
  await context.addCookies([{
    name: 'mc_cart_session',
    value,
    url: storefrontUrl,
    sameSite: 'Lax',
    expires: Math.floor(Date.now() / 1000) + (14 * 24 * 60 * 60),
  }])
}

export async function cartCookie(context: BrowserContext) {
  const cookies = await context.cookies(storefrontUrl)

  return cookies.find(cookie => cookie.name === 'mc_cart_session')
}

export function captureUnexpectedBrowserErrors(page: Page) {
  const errors: string[] = []

  page.on('pageerror', error => errors.push(`pageerror: ${error.message}`))
  page.on('console', message => {
    const text = message.text()
    const hydrationProblem = /hydration|mismatch/i.test(text)

    if (message.type() === 'error' || hydrationProblem) {
      errors.push(`${message.type()}: ${text}`)
    }
  })

  return errors
}

export async function tabTo(page: Page, locator: Locator, maximumTabs = 30) {
  for (let index = 0; index < maximumTabs; index += 1) {
    await page.keyboard.press('Tab')

    if (await locator.evaluate(element => element === document.activeElement)) {
      return
    }
  }

  throw new Error(`Could not reach ${await locator.getAttribute('aria-label') || 'target'} by keyboard.`)
}

export async function expectNoSensitiveAnalytics(
  request: APIRequestContext,
) {
  const state = await fixtureState(request)
  const serialized = JSON.stringify(state.analytics)

  expect(serialized).not.toMatch(/cart_session|recovery.*token|customer.*email/i)
  expect(serialized).not.toMatch(/supplier|purchase_price|margin|authorization/i)
}
