import { test, expect } from '@playwright/test'
import {
  expectNoSensitiveAnalytics,
  fixtureState,
  resetFixture,
  seededSession,
  setCartCookie,
} from './helpers'

test('offline mutation preserves the confirmed Cart and succeeds only after explicit retry', async ({ context, page, request }) => {
  await resetFixture(request, {
    preset: 'product',
    seed_session_id: seededSession,
  })
  await setCartCookie(context)
  await page.goto('/cart')

  const quantity = page.getByLabel('Количество')
  const update = page.getByRole('button', { name: 'Обнови', exact: true })
  await expect(page.getByRole('button', { name: /Количка, 1 продукта/ })).toBeVisible()
  await quantity.fill('2')
  await expect(update).toBeEnabled()
  await context.setOffline(true)
  await update.click()
  await expect(page.getByRole('alert')).toContainText('Възникна проблем')
  await expect(page.getByRole('link', { name: 'Тестов лаптоп' })).toBeVisible()
  await expect(page.getByText('999.90 EUR')).toBeVisible()

  let state = await fixtureState(request)
  expect(state.requests.filter(item => item.method === 'PATCH')).toHaveLength(0)
  expect(state.analytics.filter(event => event.event_name === 'add_to_cart')).toHaveLength(0)

  await context.setOffline(false)
  await page.getByRole('button', { name: 'Обнови', exact: true }).click()
  await expect(page.getByText('Количеството е обновено.')).toBeVisible()
  await expect(quantity).toHaveValue('2')
  await expect(page.getByRole('alert')).toHaveCount(0)

  state = await fixtureState(request)
  expect(state.requests.filter(item => item.method === 'PATCH')).toHaveLength(1)
  expect(state.analytics.filter(event => event.event_name === 'add_to_cart')).toHaveLength(1)
  await expectNoSensitiveAnalytics(request)
})
