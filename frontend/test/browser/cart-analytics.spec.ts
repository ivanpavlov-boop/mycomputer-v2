import { test, expect } from '@playwright/test'
import {
  expectNoSensitiveAnalytics,
  fixtureState,
  resetFixture,
  seededSession,
  setCartCookie,
} from './helpers'

test('emits authoritative add/remove deltas exactly once and never on reload or clear', async ({ context, page, request }) => {
  await resetFixture(request, {
    preset: 'product',
    seed_session_id: seededSession,
  })
  await setCartCookie(context)
  await page.goto('/cart')
  await page.waitForLoadState('networkidle')

  const quantity = page.getByLabel('Количество')
  await quantity.fill('3')
  await page.getByRole('button', { name: 'Обнови', exact: true }).click()
  await expect(page.getByText('Количеството е обновено.')).toBeVisible()

  await quantity.fill('2')
  await page.getByRole('button', { name: 'Обнови', exact: true }).click()
  await expect(quantity).toHaveValue('2')

  await page.reload()
  let state = await fixtureState(request)
  expect(state.analytics.filter(event => event.event_name === 'add_to_cart')).toHaveLength(1)
  expect(state.analytics.filter(event => event.event_name === 'remove_from_cart')).toHaveLength(1)
  expect(state.analytics[0].payload).toMatchObject({
    quantity: 2,
    value: 1999.8,
    currency: 'EUR',
  })
  expect(state.analytics[1].payload).toMatchObject({
    quantity: 1,
    value: 999.9,
    currency: 'EUR',
  })

  await page.getByRole('button', { name: 'Изчисти количката' }).click()
  await expect(page.getByText('Количката е празна')).toBeVisible()
  state = await fixtureState(request)
  expect(state.analytics).toHaveLength(2)
  await expectNoSensitiveAnalytics(request)
})

test('does not emit begin_checkout for a readiness-blocked source route', async ({ context, page, request }) => {
  await resetFixture(request, {
    preset: 'blocked',
    issue_code: 'product_inactive',
    seed_session_id: seededSession,
  })
  await setCartCookie(context)
  await page.goto('/checkout')
  await expect(page.getByRole('button', { name: 'Поръчка със задължение за плащане' })).toBeDisabled()

  const state = await fixtureState(request)
  expect(state.analytics.filter(event => event.event_name === 'begin_checkout')).toHaveLength(0)
  expect(state.orders_created).toBe(0)
  expect(state.payment_attempts).toBe(0)
})
