import { expect, test, type Page } from '@playwright/test'
import {
  fixtureState,
  resetFixture,
  seededSession,
  setCartCookie,
  storefrontUrl,
} from './helpers'

async function fillCheckout(page: Page) {
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
  await page.getByRole('checkbox', { name: 'Приемам общите условия' }).check()
}

test.describe('manual leasing checkout', () => {
  test.beforeEach(async ({ context, request }) => {
    await resetFixture(request, {
      preset: 'product',
      seed_session_id: seededSession,
      next_checkout_error: null,
    })
    await setCartCookie(context)
  })

  test('is absent in default launch mode', async ({ page }) => {
    await page.goto('/checkout')

    await expect(page.locator('input[type="radio"][value="leasing"]')).toHaveCount(0)
    await expect(page.getByRole('heading', { name: 'Покупка на изплащане' })).toHaveCount(0)
  })

  test('uses API options and submits one application without provider redirect', async ({ page, request }) => {
    await resetFixture(request, {
      preset: 'product',
      seed_session_id: seededSession,
      next_checkout_error: null,
      leasing_enabled: true,
    })
    await fillCheckout(page)
    await page.locator('input[type="radio"][value="leasing"]').check()

    await expect(page.getByRole('heading', { name: 'Покупка на изплащане' })).toBeVisible()
    await expect(page.getByLabel('Желан срок')).toHaveValue('6')
    await page.getByLabel('Желан срок').selectOption('24')
    await page.getByLabel('Желана първоначална вноска').fill('100.00')
    await page.getByLabel('Предпочитан начин за контакт').selectOption('phone')
    await page.getByLabel('Предпочитано време за контакт').selectOption('afternoon')
    await page.getByLabel('Коментар').fill('Предпочитам контакт след 14:00 ч.')
    await page.getByRole('checkbox', { name: /Съгласен\/на съм данните/ }).check()

    const checkoutRequest = page.waitForRequest(candidate => (
      candidate.method() === 'POST'
      && new URL(candidate.url()).pathname === '/api/v1/checkout'
    ))
    await page.getByRole('button', { name: 'Изпрати поръчка' }).click()

    const submitted = await checkoutRequest
    expect(submitted.postDataJSON()).toMatchObject({
      payment_method: 'leasing',
      leasing_application: {
        term_months: 24,
        down_payment: '100.00',
        contact_method: 'phone',
        contact_time: 'afternoon',
        note: 'Предпочитам контакт след 14:00 ч.',
        consent: true,
      },
    })
    await expect(page).toHaveURL(`${storefrontUrl}/checkout/success`)
    await expect(page.getByText('Получихме заявката Ви за покупка на изплащане.', { exact: true })).toBeVisible()

    const state = await fixtureState(request)
    expect(state.orders_created).toBe(1)
    expect(state.payment_attempts).toBe(1)
    expect(state.leasing_applications_created).toBe(1)
    expect(state.requests.every(entry => new URL(entry.path, storefrontUrl).origin === storefrontUrl)).toBe(true)
    expect(JSON.stringify(state.analytics)).not.toMatch(/term_months|down_payment|contact_method|contact_time|14:00/i)
    expect(page.url()).not.toMatch(/[?#]|leasing|order=/i)

    const storage = await page.evaluate(() => ({
      local: JSON.stringify(localStorage),
      session: JSON.stringify(sessionStorage),
    }))
    expect(JSON.stringify(storage)).not.toMatch(/100\.00|afternoon|14:00/i)
  })

  test('requires consent and omits leasing data after switching payment method', async ({ page }) => {
    await resetFixture(page.request, {
      preset: 'product',
      seed_session_id: seededSession,
      next_checkout_error: null,
      leasing_enabled: true,
    })
    await fillCheckout(page)
    await page.locator('input[type="radio"][value="leasing"]').check()
    await page.getByRole('button', { name: 'Изпрати поръчка' }).click()
    await expect(page.getByText('Необходимо е съгласие за обработване на заявката.')).toBeVisible()

    await page.locator('input[type="radio"][value="cash_on_delivery"]').check()
    const checkoutRequest = page.waitForRequest(candidate => (
      candidate.method() === 'POST'
      && new URL(candidate.url()).pathname === '/api/v1/checkout'
    ))
    await page.getByRole('button', { name: 'Изпрати поръчка' }).click()
    const submitted = await checkoutRequest

    expect(submitted.postDataJSON()).not.toHaveProperty('leasing_application')
  })
})
