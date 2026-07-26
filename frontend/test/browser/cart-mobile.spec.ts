import { test, expect } from '@playwright/test'
import {
  resetFixture,
  seededSession,
  setCartCookie,
} from './helpers'

test('mobile Cart page and drawer fit the viewport and keep controls reachable', async ({ context, page, request }) => {
  await resetFixture(request, {
    preset: 'product',
    seed_session_id: seededSession,
  })
  await setCartCookie(context)
  await page.goto('/cart')

  await expect(page.getByRole('link', { name: 'Тестов лаптоп' })).toBeVisible()
  await expect(page.getByLabel('Количество')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Премахни', exact: true })).toBeVisible()
  await expect(page.getByLabel('Код за купон')).toBeVisible()
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true)

  await page.locator('header').getByRole('button', {
    name: /Зареждаме количката|Количка/,
  }).click()
  const drawer = page.getByRole('dialog', { name: 'Количка' })
  await expect(drawer).toBeVisible()
  const box = await drawer.boundingBox()
  const viewport = page.viewportSize()
  expect(box?.width).toBeLessThanOrEqual(viewport?.width || 0)
  expect(box?.x).toBeGreaterThanOrEqual(0)
  await expect(drawer.getByRole('button', { name: 'Премахни', exact: true })).toBeVisible()

  await drawer.getByRole('button', { name: 'Затвори' }).click()
  await expect(drawer).toHaveCount(0)
})

test('mobile readiness and pending feedback remain visible without horizontal overflow', async ({ context, page, request }) => {
  await resetFixture(request, {
    preset: 'blocked',
    issue_code: 'insufficient_stock',
    seed_session_id: seededSession,
  })
  await setCartCookie(context)
  await page.goto('/cart')

  await expect(page.getByText('Заявеното количество не е налично.', { exact: false }).first()).toBeVisible()
  await expect(page.getByText('Прегледайте количката преди поръчка')).toBeVisible()
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true)
})
