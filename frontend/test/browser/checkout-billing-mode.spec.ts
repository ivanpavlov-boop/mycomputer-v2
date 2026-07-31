import { expect, test, type Page } from '@playwright/test'
import {
  captureUnexpectedBrowserErrors,
  fixtureState,
  resetFixture,
  seededSession,
  setCartCookie,
  storefrontUrl,
} from './helpers'

async function fillRequiredIndividualFields(page: Page) {
  await page.waitForFunction(() => {
    const root = document.querySelector('#__nuxt') as (HTMLElement & { __vue_app__?: unknown }) | null

    return Boolean(root?.__vue_app__)
  })

  await page.getByPlaceholder('Име', { exact: true }).fill('Тест')
  await page.getByPlaceholder('Фамилия').fill('Клиент')
  await page.getByPlaceholder('Имейл').fill('customer@example.test')
  await page.getByPlaceholder('Телефон').fill('+359888123456')
  await page.getByPlaceholder('Пощенски код').fill('1000')
  await page.getByPlaceholder('Адрес за доставка').fill('Тестов адрес за доставка')
  await page.getByRole('checkbox', { name: /Приемам.*Общите условия/ }).check()

  await expect(page.getByPlaceholder('Име', { exact: true })).toHaveValue('Тест')
  await expect(page.getByPlaceholder('Фамилия')).toHaveValue('Клиент')
}

async function checkoutRequest(page: Page) {
  return page.waitForRequest(candidate => (
    candidate.method() === 'POST'
    && new URL(candidate.url()).pathname === '/api/v1/checkout'
  ))
}

test.describe('individual and company Checkout billing', () => {
  test.beforeEach(async ({ context, request }) => {
    await resetFixture(request, {
      preset: 'product',
      seed_session_id: seededSession,
      next_checkout_error: null,
    })
    await setCartCookie(context)
  })

  test('submits an individual COD Order without visible or stale company fields', async ({
    page,
    request,
  }) => {
    const browserErrors = captureUnexpectedBrowserErrors(page)

    await page.goto('/checkout')

    const companyToggle = page.getByRole('checkbox', { name: 'Желая фактура на фирма' })
    await expect(companyToggle).not.toBeChecked()
    await expect(page.getByPlaceholder('Име на фирма')).toHaveCount(0)
    await expect(page.getByPlaceholder('ЕИК / ДДС номер')).toHaveCount(0)
    await expect(page.getByPlaceholder('Адрес за фактуриране')).toHaveCount(0)

    await companyToggle.check()
    await page.getByPlaceholder('Име на фирма').fill('Стара фирма')
    await page.getByPlaceholder('ЕИК / ДДС номер').fill('BG123456789')
    await page.getByPlaceholder('Адрес за фактуриране').fill('Стар фирмен адрес')
    await companyToggle.uncheck()
    await companyToggle.check()
    await expect(page.getByPlaceholder('Име на фирма')).toHaveValue('')
    await expect(page.getByPlaceholder('ЕИК / ДДС номер')).toHaveValue('')
    await expect(page.getByPlaceholder('Адрес за фактуриране')).toHaveValue('')
    await companyToggle.uncheck()

    await fillRequiredIndividualFields(page)
    await expect(page.locator('input[value="card"]')).toHaveCount(0)
    await expect(page.locator('input[value="leasing"]')).toHaveCount(0)
    const submittedPromise = checkoutRequest(page)
    await page.getByRole('button', { name: 'Поръчка със задължение за плащане' }).click()
    const submitted = await submittedPromise
    const payload = submitted.postDataJSON()

    expect(payload).toMatchObject({
      is_company: false,
      company_name: null,
      vat_number: null,
      shipping_address: 'Тестов адрес за доставка',
      billing_address: 'Тестов адрес за доставка',
      payment_method: 'cash_on_delivery',
    })
    await expect(page).toHaveURL(`${storefrontUrl}/checkout/success`)
    expect((await fixtureState(request)).orders_created).toBe(1)
    expect(browserErrors).toEqual([])
  })

  test('requires and submits explicit company billing values', async ({ page, request }) => {
    const browserErrors = captureUnexpectedBrowserErrors(page)

    await page.goto('/checkout')
    await fillRequiredIndividualFields(page)
    await page.getByRole('checkbox', { name: 'Желая фактура на фирма' }).check()

    await expect(page.getByPlaceholder('Име на фирма')).toBeVisible()
    await expect(page.getByPlaceholder('ЕИК / ДДС номер')).toBeVisible()
    await expect(page.getByPlaceholder('Адрес за фактуриране')).toBeVisible()

    await page.getByRole('button', { name: 'Поръчка със задължение за плащане' }).click()
    await page.waitForTimeout(100)
    expect((await fixtureState(request)).orders_created).toBe(0)
    await expect(page).toHaveURL(`${storefrontUrl}/checkout`)

    await page.getByPlaceholder('Име на фирма').fill('Пример ООД')
    await page.getByPlaceholder('ЕИК / ДДС номер').fill('BG123456789')
    await page.getByPlaceholder('Адрес за фактуриране').fill('София, фирмен адрес 1')

    const submittedPromise = checkoutRequest(page)
    await page.getByRole('button', { name: 'Поръчка със задължение за плащане' }).click()
    const submitted = await submittedPromise

    expect(submitted.postDataJSON()).toMatchObject({
      is_company: true,
      company_name: 'Пример ООД',
      vat_number: 'BG123456789',
      billing_address: 'София, фирмен адрес 1',
      shipping_address: 'Тестов адрес за доставка',
    })
    await expect(page).toHaveURL(`${storefrontUrl}/checkout/success`)
    expect((await fixtureState(request)).orders_created).toBe(1)
    expect(browserErrors).toEqual([])
  })
})
