import { test, expect } from '@playwright/test'
import {
  configureFixture,
  fixtureState,
  resetFixture,
  seededSession,
  setCartCookie,
} from './helpers'

const readinessMessages: Record<string, string> = {
  product_missing: 'Продуктът вече не е наличен.',
  product_deleted: 'Продуктът вече не е наличен.',
  product_inactive: 'Продуктът временно не може да бъде поръчан.',
  product_unpublished: 'Продуктът временно не може да бъде поръчан.',
  product_status_inactive: 'Продуктът временно не може да бъде поръчан.',
  product_slug_missing: 'Продуктът временно не може да бъде поръчан.',
  product_category_unavailable: 'Продуктът временно не може да бъде поръчан.',
  product_purchase_disabled: 'Поръчването на този продукт е временно недостъпно.',
  insufficient_stock: 'Заявеното количество не е налично.',
}

test.describe('Cart readiness and conflicts', () => {
  test('renders every Product eligibility issue in Bulgarian and keeps the paid line removable', async ({ context, page, request }) => {
    for (const [issueCode, message] of Object.entries(readinessMessages)) {
      await resetFixture(request, {
        preset: 'blocked',
        issue_code: issueCode,
        seed_session_id: seededSession,
      })
      await setCartCookie(context)
      await page.goto('/cart')

      await expect(page.getByText(message, { exact: false }).first()).toBeVisible()
      await expect(page.getByRole('link', { name: 'Тестов лаптоп' })).toBeVisible()
      await expect(page.getByRole('button', { name: 'Премахни', exact: true })).toBeEnabled()
      await expect(page.getByRole('link', { name: 'Към поръчка' })).toHaveCount(0)
      await expect(page.getByText('Прегледайте количката преди поръчка')).toBeVisible()
    }
  })

  test('shows price, Promotion, and mutation conflicts without replaying the mutation', async ({ context, page, request }) => {
    const conflicts = [
      ['cart_price_changed', 'Цената е променена.'],
      ['cart_promotion_changed', 'Условията на промоцията са променени.'],
      ['cart_mutation_conflict', 'Количката беше променена по време на заявката.'],
      ['cart_quantity_unavailable', 'Заявеното количество не е налично.'],
      ['cart_product_unavailable', 'Продуктът вече не е наличен за покупка.'],
    ]

    await resetFixture(request, {
      preset: 'product',
      seed_session_id: seededSession,
    })
    await setCartCookie(context)
    await page.goto('/cart')

    for (const [code, message] of conflicts) {
      await configureFixture(request, {
        fail_next_mutation: true,
        mutation_error_code: code,
      })
      await page.getByLabel('Количество').fill('2')
      await page.getByRole('button', { name: 'Обнови', exact: true }).click()
      await expect(page.getByText(message, { exact: false }).first()).toBeVisible()
      await expect(page.getByRole('link', { name: 'Тестов лаптоп' })).toBeVisible()
    }

    const state = await fixtureState(request)
    expect(state.requests.filter(item => item.method === 'PATCH')).toHaveLength(conflicts.length)
    expect(state.analytics).toEqual([])
  })

  test('keeps a gift line immutable', async ({ context, page, request }) => {
    await resetFixture(request, {
      preset: 'gift',
      seed_session_id: seededSession,
    })
    await setCartCookie(context)
    await page.goto('/cart')

    const giftText = page.getByText('Тестов подарък')
    await expect(giftText).toBeVisible()
    const giftContainer = giftText.locator('xpath=ancestor::div[contains(@class, "border-b")][1]')
    await expect(giftContainer.getByRole('button', { name: 'Обнови' })).toHaveCount(0)
    await expect(giftContainer.getByRole('button', { name: 'Премахни' })).toHaveCount(0)
  })

  test('loads the local checkout source for a ready Cart without creating an Order or payment', async ({ context, page, request }) => {
    await resetFixture(request, {
      preset: 'product',
      seed_session_id: seededSession,
    })
    await setCartCookie(context)
    await page.goto('/checkout')

    await expect(page.getByRole('heading', { name: 'Данни за клиента' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Поръчка със задължение за плащане' })).toBeEnabled()
    expect(page.url()).toBe('http://127.0.0.1:3000/checkout')

    const state = await fixtureState(request)
    expect(state.orders_created).toBe(0)
    expect(state.payment_attempts).toBe(0)
    expect(state.requests.filter(item => item.path === '/api/v1/checkout')).toHaveLength(0)
  })

  test('blocks checkout progression for an unready Cart', async ({ context, page, request }) => {
    await resetFixture(request, {
      preset: 'blocked',
      issue_code: 'insufficient_stock',
      seed_session_id: seededSession,
    })
    await setCartCookie(context)
    await page.goto('/checkout')

    await expect(page.getByText('Количката съдържа продукти, които трябва да прегледате.')).toBeVisible()
    await expect(page.getByRole('button', { name: 'Поръчка със задължение за плащане' })).toBeDisabled()
    const state = await fixtureState(request)
    expect(state.orders_created).toBe(0)
    expect(state.payment_attempts).toBe(0)
  })
})
