import { test, expect } from '@playwright/test'
import {
  configureFixture,
  resetFixture,
  seededSession,
  setCartCookie,
  tabTo,
} from './helpers'

test.describe('Cart keyboard and focus behavior', () => {
  test.beforeEach(async ({ context, request }) => {
    await resetFixture(request, {
      preset: 'product',
      seed_session_id: seededSession,
    })
    await setCartCookie(context)
  })

  test('opens the drawer by keyboard, moves focus inside, closes with Escape, and restores focus', async ({ page }) => {
    await page.goto('/cart')
    const trigger = page.locator('header').getByRole('button', {
      name: /Зареждаме количката|Количка/,
    })
    await tabTo(page, trigger)
    await page.keyboard.press('Enter')

    const drawer = page.getByRole('dialog', { name: 'Количка' })
    const close = drawer.getByRole('button', { name: 'Затвори' })
    await expect(drawer).toBeVisible()
    await expect(close).toBeFocused()

    await page.keyboard.press('Escape')
    await expect(drawer).toHaveCount(0)
    await expect(trigger).toBeFocused()
  })

  test('submits quantity and remove controls by keyboard with accessible pending and error feedback', async ({ page, request }) => {
    await page.goto('/cart')
    await configureFixture(request, { mutation_delay_ms: 200 })

    const quantity = page.getByLabel('Количество')
    await quantity.focus()
    await page.keyboard.press('ControlOrMeta+A')
    await page.keyboard.type('2')
    await page.keyboard.press('Enter')
    await expect(quantity.locator('xpath=ancestor::form[1]')).toHaveAttribute('aria-busy', 'true')
    await expect(page.getByText('Количеството е обновено.')).toBeVisible()

    await configureFixture(request, {
      fail_next_mutation: true,
      mutation_error_code: 'cart_mutation_conflict',
    })
    const remove = page.getByRole('button', { name: 'Премахни', exact: true })
    await remove.focus()
    await page.keyboard.press('Enter')
    await expect(page.getByRole('alert').first()).toContainText('Количката беше променена')
    await expect(page.getByRole('link', { name: 'Тестов лаптоп' })).toBeVisible()

    await remove.focus()
    await page.keyboard.press('Enter')
    await expect(page.getByText('Количката е празна')).toBeVisible()
  })
})
