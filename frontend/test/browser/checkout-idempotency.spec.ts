import { expect, test, type Page } from '@playwright/test'
import {
  fixtureState,
  fixtureUrl,
  resetFixture,
  seededSession,
  setCartCookie,
  storefrontUrl,
} from './helpers'

async function prepareCheckout(page: Page) {
  await page.goto('/checkout')
  await page.waitForFunction(() => {
    const root = document.querySelector('#__nuxt') as (HTMLElement & { __vue_app__?: unknown }) | null

    return Boolean(root?.__vue_app__)
  })
  await page.getByPlaceholder('Име', { exact: true }).fill('Тест')
  await page.getByPlaceholder('Фамилия').fill('Клиент')
  await page.getByPlaceholder('Имейл').fill('customer@example.test')
  await page.getByPlaceholder('Телефон').fill('+359888123456')
  await page.getByPlaceholder('Адрес за фактуриране').fill('Тестов адрес 1')
  await page.getByPlaceholder('Пощенски код').fill('1000')
  await page.getByPlaceholder('Адрес за доставка').fill('Тестов адрес 2')
  await page.getByRole('checkbox', { name: /Приемам.*Общите условия/ }).check()
}

function checkoutPayload(overrides: Record<string, unknown> = {}) {
  return {
    first_name: 'Тест',
    last_name: 'Клиент',
    email: 'customer@example.test',
    phone: '+359888123456',
    billing_address: 'Тестов адрес 1',
    shipping_address: 'Тестов адрес 2',
    shipping_provider: 'manual',
    shipping_method: 'address',
    delivery_type: 'address',
    office_id: null,
    city: 'Sofia',
    postcode: '1000',
    payment_method: 'cash_on_delivery',
    notes: '',
    terms: true,
    ...overrides,
  }
}

test.describe('Checkout idempotency', () => {
  test.beforeEach(async ({ context, request }) => {
    await resetFixture(request, {
      preset: 'product',
      seed_session_id: seededSession,
      next_checkout_error: null,
    })
    await setCartCookie(context)
  })

  test('rapid double click submits once with a valid in-memory key', async ({ page, request }) => {
    await prepareCheckout(page)
    const checkoutRequest = page.waitForRequest((candidate) => (
      candidate.method() === 'POST'
      && new URL(candidate.url()).pathname === '/api/v1/checkout'
    ))
    const button = page.getByRole('button', { name: 'Изпрати поръчка' })

    await button.evaluate((element: HTMLButtonElement) => {
      element.click()
      element.click()
    })

    const submitted = await checkoutRequest
    expect(submitted.headers()['idempotency-key']).toMatch(/^[A-Za-z0-9_-]{43}$/u)
    await expect(page).toHaveURL(`${storefrontUrl}/checkout/success`)

    const state = await fixtureState(request)
    const checkoutRequests = state.requests.filter(entry => entry.path === '/api/v1/checkout')
    expect(checkoutRequests).toHaveLength(1)
    expect(checkoutRequests[0]).toMatchObject({
      idempotency_key_present: true,
      idempotency_key_valid: true,
    })
    expect(state.orders_created).toBe(1)
    expect(state.payment_attempts).toBe(1)
    expect(page.url()).not.toMatch(/[?&]idempotency/i)
    expect(await page.evaluate(() => ({
      local: Object.keys(localStorage),
      session: Object.keys(sessionStorage),
      history: history.state,
    }))).not.toMatchObject({
      local: expect.arrayContaining([expect.stringMatching(/idempotency/i)]),
      session: expect.arrayContaining([expect.stringMatching(/idempotency/i)]),
    })
    expect(JSON.stringify(state.analytics)).not.toMatch(/idempotency|key_hash|request_hash/i)
  })

  test('lost response keeps the key and explicit retry returns the same Order', async ({ page, request }) => {
    await resetFixture(request, {
      preset: 'product',
      seed_session_id: seededSession,
      next_checkout_error: null,
      lose_next_checkout_response: true,
    })
    await prepareCheckout(page)
    const button = page.getByRole('button', { name: 'Изпрати поръчка' })

    await button.click()
    await expect(page.getByText('Възникна проблем. Моля, опитайте отново.')).toBeVisible()

    const committed = await fixtureState(request)
    expect(committed.orders_created).toBe(1)
    expect(committed.payment_attempts).toBe(1)
    expect(committed.requests.filter(entry => entry.path === '/api/v1/checkout')).toHaveLength(1)

    await button.click()
    await expect(page).toHaveURL(`${storefrontUrl}/checkout/success`)
    await expect(page.getByText('MC-FIXTURE-0001')).toBeVisible()

    const replayed = await fixtureState(request)
    const checkoutRequests = replayed.requests.filter(entry => entry.path === '/api/v1/checkout')
    expect(checkoutRequests).toHaveLength(2)
    expect(checkoutRequests[0]?.idempotency_identity).toBe(checkoutRequests[1]?.idempotency_identity)
    expect(replayed.orders_created).toBe(1)
    expect(replayed.payment_attempts).toBe(1)
    expect(replayed.confirmation_capabilities).toBe(2)
  })

  test('equivalent alternate key replays and changed payload conflicts', async ({ request }) => {
    const headers = {
      Origin: storefrontUrl,
      'X-Cart-Session': seededSession,
      'Idempotency-Key': 'A'.repeat(43),
    }
    const first = await request.post(`${fixtureUrl}/api/v1/checkout`, {
      headers,
      data: checkoutPayload(),
    })
    const alternate = await request.post(`${fixtureUrl}/api/v1/checkout`, {
      headers: { ...headers, 'Idempotency-Key': 'B'.repeat(43) },
      data: checkoutPayload(),
    })
    const conflict = await request.post(`${fixtureUrl}/api/v1/checkout`, {
      headers,
      data: checkoutPayload({ notes: 'Different checkout data' }),
    })

    expect(first.status()).toBe(201)
    expect(alternate.status()).toBe(201)
    expect((await alternate.json()).data).toMatchObject({
      order_number: (await first.json()).data.order_number,
      idempotent_replay: true,
    })
    expect(conflict.status()).toBe(409)
    expect((await conflict.json()).error.code).toBe('checkout_idempotency_conflict')

    const state = await fixtureState(request)
    expect(state.orders_created).toBe(1)
    expect(state.payment_attempts).toBe(1)
    expect(state.confirmation_capabilities).toBe(2)
    expect(JSON.stringify(state)).not.toContain('A'.repeat(43))
    expect(JSON.stringify(state)).not.toContain('B'.repeat(43))
  })
})
