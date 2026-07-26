import { test, expect } from '@playwright/test'
import {
  captureUnexpectedBrowserErrors,
  cartCookie,
  fixtureState,
  resetFixture,
  seededSession,
  setCartCookie,
  storefrontUrl,
} from './helpers'

test.describe('Cart SSR and hydration', () => {
  test.beforeEach(async ({ request }) => {
    await resetFixture(request)
  })

  test('renders a confirmed server Cart and hydrates without replacing its identity', async ({ context, page, request }) => {
    await resetFixture(request, {
      preset: 'product',
      seed_session_id: seededSession,
    })
    await setCartCookie(context)
    const browserErrors = captureUnexpectedBrowserErrors(page)

    const serverResponse = await request.get(`${storefrontUrl}/cart`, {
      headers: {
        Cookie: `mc_cart_session=${seededSession}`,
      },
    })
    expect(serverResponse.ok()).toBe(true)
    expect(await serverResponse.text()).toContain('Тестов лаптоп')

    await page.goto('/cart')
    await expect(page.getByRole('heading', { name: 'Количка', exact: true })).toBeVisible()
    await expect(page.getByRole('link', { name: 'Тестов лаптоп' })).toBeVisible()
    await expect(page.getByText('Количката е празна')).toHaveCount(0)
    await page.waitForTimeout(100)

    expect(await cartCookie(context)).toMatchObject({
      value: seededSession,
      path: '/',
      sameSite: 'Lax',
    })
    expect(browserErrors).toEqual([])

    const state = await fixtureState(request)
    const cartRequests = state.requests.filter(item => item.path === '/api/v1/cart')
    expect(cartRequests.length).toBeGreaterThanOrEqual(2)
    expect(cartRequests.every(item => item.cart_session === seededSession)).toBe(true)
    expect(state.sessions).toHaveLength(1)
  })

  test('creates and persists a canonical guest Cart only after the backend response', async ({ context, page, request }) => {
    const browserErrors = captureUnexpectedBrowserErrors(page)

    await page.goto('/cart')
    await expect(page.getByText('Количката е празна')).toBeVisible()

    const cookie = await cartCookie(context)
    expect(cookie?.value).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-8[0-9a-f]{3}-[0-9a-f]{12}$/)
    expect(cookie).toMatchObject({
      path: '/',
      sameSite: 'Lax',
    })
    expect(cookie?.expires).toBeGreaterThan(Math.floor(Date.now() / 1000))
    expect(browserErrors).toEqual([])

    const state = await fixtureState(request)
    const firstCartRequest = state.requests.find(item => item.path === '/api/v1/cart')
    expect(firstCartRequest?.cart_session).toBeNull()
    expect(state.sessions).toHaveLength(1)
    expect(state.sessions[0].cart.cart_session_id).toBe(cookie?.value)
  })

  test('recovers from a malformed cookie without sending it to the API', async ({ context, page, request }) => {
    await setCartCookie(context, 'not-a-cart-uuid')

    await page.goto('/cart')
    await expect(page.getByText('Количката е празна')).toBeVisible()

    const cookie = await cartCookie(context)
    expect(cookie?.value).not.toBe('not-a-cart-uuid')
    expect(cookie?.value).toMatch(/^[0-9a-f-]{36}$/)

    const state = await fixtureState(request)
    const cartRequests = state.requests.filter(item => item.path === '/api/v1/cart')
    expect(cartRequests.every(item => item.cart_session !== 'not-a-cart-uuid')).toBe(true)
    expect(state.analytics).toEqual([])
  })

  test('isolates Cart identity between separate browser contexts', async ({ browser, request }) => {
    const firstContext = await browser.newContext()
    const secondContext = await browser.newContext()

    try {
      const firstPage = await firstContext.newPage()
      const secondPage = await secondContext.newPage()
      await firstPage.goto('/cart')
      await secondPage.goto('/cart')

      const firstCookie = await cartCookie(firstContext)
      const secondCookie = await cartCookie(secondContext)
      expect(firstCookie?.value).toBeTruthy()
      expect(secondCookie?.value).toBeTruthy()
      expect(firstCookie?.value).not.toBe(secondCookie?.value)
    } finally {
      await firstContext.close()
      await secondContext.close()
    }
  })
})
