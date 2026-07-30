import { test, expect } from '@playwright/test'
import {
  configureFixture,
  expectNoSensitiveAnalytics,
  fixtureState,
  resetFixture,
  seededSession,
  setCartCookie,
} from './helpers'

test.describe('Cart mutation controls', () => {
  test.beforeEach(async ({ context, request }) => {
    await resetFixture(request, {
      preset: 'product',
      seed_session_id: seededSession,
    })
    await setCartCookie(context)
  })

  test('submits one quantity request, blocks duplicates, and emits one delta event', async ({ page, request }) => {
    await page.goto('/cart')
    await page.waitForLoadState('networkidle')
    await configureFixture(request, { mutation_delay_ms: 250 })

    const quantity = page.getByLabel('Количество')
    const update = page.getByRole('button', { name: 'Обнови', exact: true })
    await quantity.fill('2')
    await update.click()
    await expect(update).toBeDisabled()
    await update.click({ force: true })
    await expect(page.getByText('Количеството е обновено.')).toBeVisible()

    const state = await fixtureState(request)
    expect(state.requests.filter(item => item.method === 'PATCH' && item.path === '/api/v1/cart/items/501')).toHaveLength(1)
    expect(state.analytics.filter(event => event.event_name === 'add_to_cart')).toHaveLength(1)
    expect(state.analytics[0].payload).toMatchObject({
      product_id: 101,
      quantity: 1,
      unit_price: 999.9,
      currency: 'EUR',
    })
  })

  test('keeps a line on failed remove, then removes it after an explicit retry', async ({ page, request }) => {
    await page.goto('/cart')
    await configureFixture(request, {
      fail_next_mutation: true,
      mutation_error_code: 'cart_mutation_conflict',
    })

    const remove = page.getByRole('button', { name: 'Премахни', exact: true })
    await remove.click()
    await expect(page.getByRole('link', { name: 'Тестов лаптоп' })).toBeVisible()
    await expect(page.getByRole('alert').first()).toContainText('Количката беше променена')

    let state = await fixtureState(request)
    expect(state.analytics.filter(event => event.event_name === 'remove_from_cart')).toHaveLength(0)

    await remove.click()
    await expect(page.getByText('Количката е празна')).toBeVisible()
    await expect.poll(async () => {
      state = await fixtureState(request)

      return state.analytics.filter(event => event.event_name === 'remove_from_cart')
    }).toHaveLength(1)
  })

  test('preserves the confirmed Cart when clear fails and accepts only a confirmed empty response', async ({ page, request }) => {
    await page.goto('/cart')
    await configureFixture(request, {
      fail_next_mutation: true,
      mutation_error_code: 'request_failed',
      mutation_delay_ms: 750,
    })

    const clear = page.getByRole('button', { name: /Изчисти количката|Изчистване/ })
    await clear.click()
    await expect(clear).toBeDisabled()
    await expect(page.getByRole('link', { name: 'Тестов лаптоп' })).toBeVisible()
    await expect(page.getByRole('alert')).toContainText('Възникна проблем')

    await clear.click()
    await expect(page.getByText('Количката е празна')).toBeVisible()
    const state = await fixtureState(request)
    expect(state.analytics).toEqual([])
  })

  test('applies, validates, and removes coupons without optimistic replacement', async ({ page, request }) => {
    await page.goto('/cart')
    await expect(page.getByRole('button', { name: /Количка, 1 продукта/ })).toBeVisible()
    const coupon = page.getByLabel('Код за купон')
    const apply = page.getByRole('button', { name: 'Приложи' })

    await coupon.fill('INVALID')
    await expect(apply).toBeEnabled()
    await apply.click()
    await expect(page.getByRole('alert')).toContainText('Проверете въведените данни')
    await expect(coupon).toHaveValue('INVALID')

    await coupon.fill('SAVE10')
    await expect(apply).toBeEnabled()
    await apply.click()
    await expect(page.getByRole('status')).toContainText('Купонът е приложен')
    await expect(coupon).toHaveValue('SAVE10')

    await page.getByRole('button', { name: 'Премахни', exact: true }).last().click()
    await expect(page.getByRole('status')).toContainText('Купонът е премахнат')
    await expect(coupon).toHaveValue('')

    const state = await fixtureState(request)
    expect(state.requests.filter(item => item.path === '/api/v1/cart/coupon')).toHaveLength(3)
  })

  test('keeps gift lines immutable in the visible Cart', async ({ context, page, request }) => {
    await resetFixture(request, {
      preset: 'gift',
      seed_session_id: seededSession,
    })
    await setCartCookie(context)
    await page.goto('/cart')

    await expect(page.getByText('Тестов подарък')).toBeVisible()
    await expect(page.getByText('Подарък от активна промоция')).toBeVisible()
    await expect(page.getByText('Тестов подарък').locator('..').getByLabel('Количество')).toHaveCount(0)
    await expect(page.getByText('Тестов подарък').locator('..').getByRole('button', { name: 'Премахни' })).toHaveCount(0)
  })

  test('adds one bundle through the visible control and blocks a duplicate click', async ({ context, page, request }) => {
    await resetFixture(request)
    await context.clearCookies()
    await configureFixture(request, { mutation_delay_ms: 250 })
    await page.goto('/bundles/testov-komplekt')

    const add = page.getByRole('button', { name: /Добави комплекта|Добавяне/ })
    await add.click()
    await expect(add).toBeDisabled()
    await add.click({ force: true })
    await expect(page.getByText('Комплектът е добавен в количката.')).toBeVisible()

    const state = await fixtureState(request)
    expect(state.requests.filter(item => item.method === 'POST' && item.path === '/api/v1/cart/bundles')).toHaveLength(1)
    expect(state.analytics.filter(event => event.event_name === 'add_to_cart')).toHaveLength(1)
    expect(state.sessions[0].cart.bundle_items).toHaveLength(1)
    await expectNoSensitiveAnalytics(request)
  })
})
