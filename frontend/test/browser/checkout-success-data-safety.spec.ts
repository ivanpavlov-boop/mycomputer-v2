import { test, expect, type Page } from '@playwright/test'
import {
  captureUnexpectedBrowserErrors,
  configureFixture,
  fixtureUrl,
  fixtureState,
  resetFixture,
  seededSession,
  setCartCookie,
  storefrontUrl,
} from './helpers'

async function submitCheckout(page: Page) {
  await page.goto('/checkout')
  await page.waitForFunction(() => {
    const root = document.querySelector('#__nuxt') as (HTMLElement & { __vue_app__?: unknown }) | null

    return Boolean(root?.__vue_app__)
  })

  const email = page.getByPlaceholder('Имейл')
  await page.getByPlaceholder('Телефон').fill('+359888123456')
  await page.getByPlaceholder('Пощенски код').fill('1000')
  await page.getByPlaceholder('Адрес за доставка').fill('Тестов адрес 2')
  await page.getByPlaceholder('Име', { exact: true }).fill('Тест')
  await page.getByPlaceholder('Фамилия').fill('Клиент')
  await email.fill('customer@example.test')
  await page.getByRole('checkbox', { name: /Приемам.*Общите условия/ }).check()
  await expect(email).toHaveValue('customer@example.test')

  const checkoutResponse = page.waitForResponse((response) => {
    return response.request().method() === 'POST'
      && new URL(response.url()).pathname === '/api/v1/checkout'
  })
  await page.getByRole('button', { name: 'Поръчка със задължение за плащане' }).click()

  const checkout = await checkoutResponse
  await expect(page).toHaveURL(`${storefrontUrl}/checkout/success`)
  await expect(page.getByText('Поръчката е приета')).toBeVisible()

  return checkout
}

test.describe('Checkout success data safety', () => {
  test.beforeEach(async ({ context, request }) => {
    await resetFixture(request, {
      preset: 'product',
      seed_session_id: seededSession,
      next_checkout_error: null,
    })
    await setCartCookie(context)
  })

  test('uses a clean URL and an HttpOnly capability for trusted confirmation', async ({ context, page, request }) => {
    const browserErrors = captureUnexpectedBrowserErrors(page)
    const checkoutResponse = await submitCheckout(page)

    expect(checkoutResponse.status()).toBe(201)
    expect(await checkoutResponse.json()).toEqual({
      data: {
        accepted: true,
        order_number: 'MC-FIXTURE-0001',
        grand_total: '1006.80',
        currency: 'EUR',
        payment_method: 'cash_on_delivery',
        payment_status: 'pending',
        idempotent_replay: false,
      },
    })
    await expect(page.getByText('MC-FIXTURE-0001')).toBeVisible()
    await expect(page.getByText('c***@example.test')).toBeVisible()
    await expect(page.getByText('Статус на поръчката: Очаква обработка')).toBeVisible()
    await expect(page.getByText('Начин на плащане: Наложен платеж')).toBeVisible()
    await expect(page.getByText('Плащане при доставка')).toBeVisible()
    await expect(page.getByText('Ще заплатите сумата при получаване на поръчката.')).toBeVisible()

    const visibleConfirmation = await page.locator('main').innerText()
    expect(visibleConfirmation).not.toContain('Статус: pending')
    expect(visibleConfirmation).not.toMatch(/\bpending\b/)
    expect(visibleConfirmation).not.toContain('cash_on_delivery')
    expect(visibleConfirmation).not.toContain('Наложен платеж · pending')

    const historyUrls = await page.evaluate(() => {
      return performance.getEntriesByType('navigation').map(entry => entry.name)
    })
    expect(historyUrls.join(' ')).not.toMatch(/customer@example|payment_method|grand_total|token=/i)
    expect(page.url()).not.toMatch(/[?#]/)

    const confirmationCookie = (await context.cookies(storefrontUrl))
      .find(cookie => cookie.name === 'mc_checkout_confirmation')
    expect(confirmationCookie).toMatchObject({
      httpOnly: true,
      secure: false,
      sameSite: 'Lax',
      path: '/',
    })
    expect(confirmationCookie?.domain).toBe('127.0.0.1')
    expect(await page.evaluate(() => document.cookie)).not.toContain('mc_checkout_confirmation')

    const state = await fixtureState(request)
    expect(state.requests.filter(entry => (
      entry.method === 'GET'
      && entry.path === '/api/v1/checkout/confirmation'
    ))).toHaveLength(1)
    expect(state.orders_created).toBe(1)
    expect(state.payment_attempts).toBe(1)
    expect(state.confirmation_capabilities).toBe(1)
    expect(JSON.stringify(state)).not.toContain(confirmationCookie?.value)
    expect(state.analytics.filter(event => event.event_name === 'purchase')).toHaveLength(1)
    expect(state.analytics.find(event => event.event_name === 'purchase')?.payload).toEqual({
      order_number: 'MC-FIXTURE-0001',
      value: 1006.8,
      currency: 'EUR',
    })

    const confirmationResponse = await request.get(`${fixtureUrl}/api/v1/checkout/confirmation`, {
      headers: {
        Cookie: `mc_checkout_confirmation=${confirmationCookie?.value}`,
      },
    })
    expect(confirmationResponse.status()).toBe(200)
    expect(confirmationResponse.headers()['cache-control']).toContain('no-store')

    await page.reload()
    await expect(page.getByText('Поръчката е приета')).toBeVisible()
    expect(browserErrors).toEqual([])
  })

  test('does not authorize direct or query-tampered success URLs', async ({ context, page }) => {
    await context.clearCookies()
    await page.goto('/checkout/success?order_number=MC-FAKE&email=attacker@example.test#token')

    await expect(page).toHaveURL(`${storefrontUrl}/checkout/success`)
    await expect(page.getByText('Потвърждението за поръчката не е налично.')).toBeVisible()
    await expect(page.getByText('MC-FAKE')).toHaveCount(0)
    await expect(page.getByText('attacker@example.test')).toHaveCount(0)
  })

  test('fails closed for malformed, unknown, and expired capabilities', async ({ context, page, request }) => {
    const unavailable = page.getByText('Потвърждението за поръчката не е налично.')

    await context.clearCookies()
    await context.addCookies([{
      name: 'mc_checkout_confirmation',
      value: 'malformed',
      url: storefrontUrl,
      httpOnly: true,
      sameSite: 'Lax',
    }])
    await page.goto('/checkout/success')
    await expect(unavailable).toBeVisible()

    await context.clearCookies()
    await context.addCookies([{
      name: 'mc_checkout_confirmation',
      value: 'A'.repeat(43),
      url: storefrontUrl,
      httpOnly: true,
      sameSite: 'Lax',
    }])
    await page.goto('/checkout/success')
    await expect(unavailable).toBeVisible()

    await context.clearCookies()
    await setCartCookie(context)
    await configureFixture(request, { expire_confirmation: false })
    await submitCheckout(page)
    await expect(page.getByText('Поръчката е приета')).toBeVisible()

    await configureFixture(request, { expire_confirmation: true })
    await page.reload()
    await expect(unavailable).toBeVisible()

    const state = await fixtureState(request)
    expect(state.orders_created).toBe(1)
    expect(state.payment_attempts).toBe(1)
  })
})
