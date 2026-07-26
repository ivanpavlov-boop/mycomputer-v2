import { test, expect } from '@playwright/test'
import {
  cartCookie,
  configureFixture,
  fixtureState,
  resetFixture,
  seededSession,
  setCartCookie,
} from './helpers'

test.describe('Persistent Cart lifecycle', () => {
  test.beforeEach(async ({ context, request }) => {
    await resetFixture(request, {
      preset: 'product',
      seed_session_id: seededSession,
    })
    await setCartCookie(context)
  })

  test('keeps the confirmed Cart after a visible quantity add and hard reload', async ({ context, page, request }) => {
    await page.goto('/cart')
    await expect(page.getByRole('button', { name: /Количка, 1 продукта/ })).toBeVisible()
    const quantity = page.getByLabel('Количество')
    await quantity.fill('2')
    await configureFixture(request, { mutation_delay_ms: 150 })

    const update = page.getByRole('button', { name: 'Обнови', exact: true })
    await update.click()
    await expect(update).toBeDisabled()
    await expect(page.getByText('Количеството е обновено.')).toBeVisible()
    await expect(quantity).toHaveValue('2')

    const beforeReload = await cartCookie(context)
    await page.reload()
    await expect(page.getByRole('link', { name: 'Тестов лаптоп' })).toBeVisible()
    await expect(page.getByLabel('Количество')).toHaveValue('2')
    expect((await cartCookie(context))?.value).toBe(beforeReload?.value)

    const state = await fixtureState(request)
    const updateRequests = state.requests.filter(item => item.method === 'PATCH' && item.path === '/api/v1/cart/items/501')
    expect(updateRequests).toHaveLength(1)
    expect(state.analytics.filter(event => event.event_name === 'add_to_cart')).toHaveLength(1)
    expect(state.sessions).toHaveLength(1)
  })

  test('persists the Cart across a browser restart simulation', async ({ browser, request }, testInfo) => {
    const firstContext = await browser.newContext()
    await setCartCookie(firstContext)
    const firstPage = await firstContext.newPage()
    await firstPage.goto('/cart')
    await expect(firstPage.getByRole('link', { name: 'Тестов лаптоп' })).toBeVisible()

    const storagePath = testInfo.outputPath('storage-state.json')
    await firstContext.storageState({ path: storagePath })
    const originalCookie = await cartCookie(firstContext)
    await firstContext.close()

    const restartedContext = await browser.newContext({ storageState: storagePath })
    try {
      const restartedPage = await restartedContext.newPage()
      await restartedPage.goto('/cart')
      await expect(restartedPage.getByRole('link', { name: 'Тестов лаптоп' })).toBeVisible()
      expect((await cartCookie(restartedContext))?.value).toBe(originalCookie?.value)

      const state = await fixtureState(request)
      expect(state.sessions).toHaveLength(1)
    } finally {
      await restartedContext.close()
    }
  })

  for (const reason of ['expired', 'converted']) {
    test(`persists backend ${reason} session rotation before showing the returned Cart`, async ({ context, page, request }) => {
      await configureFixture(request, { rotate_next_get: reason })
      await page.goto('/cart')
      await expect(page.getByRole('link', { name: 'Тестов лаптоп' })).toBeVisible()

      const rotated = await cartCookie(context)
      expect(rotated?.value).not.toBe(seededSession)

      await page.reload()
      const state = await fixtureState(request)
      const cartRequests = state.requests.filter(item => item.path === '/api/v1/cart')
      expect(cartRequests[0].cart_session).toBe(seededSession)
      expect(cartRequests.at(-1)?.cart_session).toBe(rotated?.value)
      expect(state.sessions).toHaveLength(1)
    })
  }
})
