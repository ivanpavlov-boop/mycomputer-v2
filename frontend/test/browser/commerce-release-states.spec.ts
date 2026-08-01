import { expect, test } from '@playwright/test'

const fixtureUrl = 'http://127.0.0.1:4010'
const seededSession = '11111111-1111-4111-8111-111111111111'
const state = process.env.COMMERCE_TEST_STATE
const storefrontPort = state === 'closed' ? 3001 : 3002
const storefrontUrl = `http://127.0.0.1:${storefrontPort}`

test('approved legal pages remain available and indexable in every release state', async ({ page }) => {
  for (const path of ['/obshti-usloviya', '/politika-za-poveritelnost']) {
    const response = await page.goto(path)

    expect(response?.status(), path).toBe(200)
    await expect(page.getByText('Версия', { exact: true })).toBeVisible()
    await expect(page.getByText('2026-07-30', { exact: true })).toBeVisible()
    await expect(page.getByText('Проект за правен преглед')).toHaveCount(0)
    await expect(page.locator('meta[name="robots"]')).toHaveAttribute(
      'content',
      'index, follow',
    )
  }

  for (const path of [
    '/en/terms',
    '/en/privacy',
    '/en/obshti-usloviya',
    '/en/politika-za-poveritelnost',
  ]) {
    expect((await page.goto(path))?.status(), path).toBe(404)
  }
})

test('closed mode returns real 404 responses and exposes no Cart entry', async ({ page }) => {
  test.skip(state !== 'closed')

  for (const path of [
    '/cart',
    '/checkout',
    '/checkout/success',
    '/cart/recover',
    '/cart/recover/historical-token',
    '/en/cart',
    '/en/checkout',
    '/en/checkout/success',
  ]) {
    const response = await page.goto(path)

    expect(response?.status(), path).toBe(404)
  }

  await page.goto('/catalog')
  await expect(page.getByRole('link', { name: /Количка/ })).toHaveCount(0)
})

test('confirmation-only mode preserves missing and valid confirmation behavior', async ({
  context,
  page,
  request,
}) => {
  test.skip(state !== 'confirmation_only')

  expect((await page.goto('/cart'))?.status()).toBe(404)
  expect((await page.goto('/checkout'))?.status()).toBe(404)
  expect((await page.goto('/cart/recover'))?.status()).toBe(404)
  expect((await page.goto('/en/checkout/success'))?.status()).toBe(404)

  const missing = await page.goto('/checkout/success')
  expect(missing?.status()).toBe(200)
  await expect(page.getByText(/Потвърждението за поръчката не е налично/)).toBeVisible()

  expect((await request.post(`${fixtureUrl}/__test/reset`)).ok()).toBe(true)
  const configured = await request.post(`${fixtureUrl}/__test/scenario`, {
    data: {
      preset: 'product',
      seed_session_id: seededSession,
      next_checkout_error: null,
    },
  })
  expect(configured.ok()).toBe(true)
  const configuredBody = await configured.json()
  const seededCart = configuredBody.data.sessions
    .find((session: { cart: { cart_session_id: string } }) => (
      session.cart.cart_session_id === seededSession
    ))

  expect(seededCart?.cart.readiness.can_checkout).toBe(true)

  const checkout = await request.post(`${fixtureUrl}/api/v1/checkout`, {
    headers: {
      'X-Cart-Session': seededSession,
      'Idempotency-Key': 'a'.repeat(43),
    },
    data: {
      first_name: 'Тест',
      last_name: 'Клиент',
      email: 'confirmation@example.test',
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
    },
  })
  expect(checkout.status(), await checkout.text()).toBe(201)

  const confirmationCookie = checkout.headersArray()
    .find(header => header.name.toLowerCase() === 'set-cookie'
      && header.value.startsWith('mc_checkout_confirmation='))
  const token = confirmationCookie?.value.match(/mc_checkout_confirmation=([^;]+)/)?.[1]

  expect(token).toBeTruthy()
  await context.addCookies([{
    name: 'mc_checkout_confirmation',
    value: token!,
    url: storefrontUrl,
    httpOnly: true,
    sameSite: 'Lax',
  }])

  expect((await page.goto('/checkout/success'))?.status()).toBe(200)
  await expect(page.getByText(/MC-FIXTURE-/)).toBeVisible()
  await expect(page.getByRole('link', { name: /Количка/ })).toHaveCount(0)
  await expect(page.getByRole('link', { name: 'Вход' })).toHaveCount(0)
  await expect(page.getByRole('link', { name: 'Регистрация' })).toHaveCount(0)
  await expect(page.getByRole('link', { name: /Сравни/ })).toHaveCount(0)
})
