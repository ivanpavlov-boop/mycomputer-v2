import { test, expect } from '@playwright/test'
import {
  fixtureState,
  resetFixture,
  seededSession,
  setCartCookie,
} from './helpers'

test('same-context tabs converge through the authoritative backend Cart', async ({ context, page, request }) => {
  await resetFixture(request, {
    preset: 'product',
    seed_session_id: seededSession,
  })
  await setCartCookie(context)

  await page.goto('/cart')
  const secondPage = await context.newPage()
  await secondPage.goto('/cart')
  await expect(secondPage.getByLabel('Количество')).toHaveValue('1')

  await secondPage.getByLabel('Количество').fill('3')
  await secondPage.getByRole('button', { name: 'Обнови', exact: true }).click()
  await expect(secondPage.getByText('Количеството е обновено.')).toBeVisible()

  await page.reload()
  await expect(page.getByLabel('Количество')).toHaveValue('3')

  const state = await fixtureState(request)
  const cartSessions = state.requests
    .filter(item => item.path === '/api/v1/cart')
    .map(item => item.cart_session)
  expect(new Set(cartSessions)).toEqual(new Set([seededSession]))
  expect(state.sessions).toHaveLength(1)
})
