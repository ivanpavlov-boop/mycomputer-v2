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
  await page.getByPlaceholder('Пощенски код').fill('1000')
  await page.getByPlaceholder('Адрес за доставка').fill('Тестов адрес 2')
  const shippingProvider = page.locator('select').first()
  await expect(shippingProvider.locator('option[value="manual"]')).toHaveCount(1)
  await shippingProvider.selectOption('manual')
  await page.getByRole('checkbox', { name: /Приемам.*Общите условия/ }).check()
}

async function submitWithMethod(page: Page, paymentMethod: string) {
  await fillCheckout(page)
  await page.locator(`input[type="radio"][value="${paymentMethod}"]`).check()
  await page.getByPlaceholder('Име', { exact: true }).fill('Тест')
  await page.getByPlaceholder('Фамилия').fill('Клиент')
  await page.getByPlaceholder('Имейл').fill('customer@example.test')
  await page.getByPlaceholder('Телефон').fill('+359888123456')
  await page.getByPlaceholder('Пощенски код').fill('1000')
  await page.getByPlaceholder('Адрес за доставка').fill('Тестов адрес 2')
  await page.getByRole('checkbox', { name: /Приемам.*Общите условия/ }).check()
  await page.getByRole('button', { name: 'Поръчка със задължение за плащане' }).click()
  await expect(page).toHaveURL(`${storefrontUrl}/checkout/success`)
}

test.describe('checkout payment acceptance', () => {
  test.beforeEach(async ({ context, request }) => {
    await resetFixture(request, {
      preset: 'product',
      seed_session_id: seededSession,
      next_checkout_error: null,
    })
    await setCartCookie(context)
  })

  test('offline checkout has a Bulgarian status and no payment action', async ({ page, request }) => {
    await submitWithMethod(page, 'cash_on_delivery')

    await expect(page.getByText('Плащането ще бъде извършено при доставката.')).toBeVisible()
    await expect(page.getByRole('link', { name: 'Продължи към плащане' })).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'Опитай плащането отново' })).toHaveCount(0)

    const state = await fixtureState(request)
    expect(state.orders_created).toBe(1)
    expect(state.payment_transactions).toBe(1)
    expect(state.payment_retry_attempts).toBe(0)
    expect(state.provider_invocations).toBe(0)
    expect(state.notifications_dispatched).toBe(1)
  })

  test('legal links open separately without toggling consent or submitting an order', async ({ page, request }) => {
    await page.goto('/checkout')
    const checkbox = page.getByRole('checkbox', { name: /Приемам.*Общите условия/ })
    const terms = page.getByRole('link', { name: 'Общите условия' })
    const privacy = page.getByRole('link', { name: 'Политиката за поверителност' })

    await expect(checkbox).not.toBeChecked()
    await expect(terms).toHaveAttribute('href', '/obshti-usloviya')
    await expect(terms).toHaveAttribute('target', '_blank')
    await expect(terms).toHaveAttribute('rel', 'noopener noreferrer')
    await expect(privacy).toHaveAttribute('href', '/politika-za-poveritelnost')
    await expect(privacy).toHaveAttribute('target', '_blank')
    await expect(privacy).toHaveAttribute('rel', 'noopener noreferrer')

    const [legalPage] = await Promise.all([
      page.waitForEvent('popup'),
      terms.click(),
    ])
    await expect(legalPage).toHaveURL('/obshti-usloviya')
    await legalPage.close()
    await expect(checkbox).not.toBeChecked()
    await expect(page).toHaveURL('/checkout')
    await expect(page.getByRole('button', {
      name: 'Поръчка със задължение за плащане',
    })).toBeVisible()

    expect((await fixtureState(request)).orders_created).toBe(0)
  })

  test('online continuation is explicit, HTTPS-only, and does not auto-navigate', async ({ page, request }) => {
    await resetFixture(request, {
      preset: 'product',
      seed_session_id: seededSession,
      next_checkout_error: null,
      card_enabled: true,
      card_payment_state: 'pending',
      card_redirect_url: 'https://payments.example.test/continue',
    })
    await submitWithMethod(page, 'card')

    const action = page.getByRole('link', { name: 'Продължи към плащане' })
    await expect(action).toBeVisible()
    await expect(action).toHaveAttribute('href', 'https://payments.example.test/continue')
    await expect(action).toHaveAttribute('target', '_blank')
    await expect(action).toHaveAttribute('rel', 'noopener noreferrer')
    await page.waitForTimeout(250)
    await expect(page).toHaveURL(`${storefrontUrl}/checkout/success`)

    const state = await fixtureState(request)
    expect(state.orders_created).toBe(1)
    expect(state.payment_transactions).toBe(1)
    expect(state.provider_invocations).toBe(1)
    expect(state.payment_retry_attempts).toBe(0)
  })

  test('lost retry response replays the same explicit attempt without duplication', async ({ page, request }) => {
    await resetFixture(request, {
      preset: 'product',
      seed_session_id: seededSession,
      next_checkout_error: null,
      card_enabled: true,
      card_payment_state: 'failed',
      card_retry_state: 'authorized',
      card_redirect_url: 'https://payments.example.test/retry',
      lose_next_payment_attempt_response: true,
    })
    await submitWithMethod(page, 'card')

    const retry = page.getByRole('button', { name: 'Опитай плащането отново' })
    await expect(retry).toBeVisible()
    await retry.click()
    await expect(page.getByRole('alert')).toBeVisible()

    let state = await fixtureState(request)
    expect(state.payment_retry_attempts).toBe(1)
    expect(state.provider_invocations).toBe(2)

    await retry.click()
    await expect(page.getByRole('link', { name: 'Продължи към плащане' })).toBeVisible()

    state = await fixtureState(request)
    const attempts = state.requests.filter(entry => entry.path === '/api/v1/checkout/payment-attempts')
    expect(attempts).toHaveLength(2)
    expect(attempts[0]?.idempotency_identity).toBe(attempts[1]?.idempotency_identity)
    expect(state.orders_created).toBe(1)
    expect(state.payment_transactions).toBe(1)
    expect(state.payment_retry_attempts).toBe(1)
    expect(state.provider_invocations).toBe(2)
  })

  test('unsafe provider redirect fails closed without exposing an action', async ({ page, request }) => {
    await resetFixture(request, {
      preset: 'product',
      seed_session_id: seededSession,
      next_checkout_error: null,
      card_enabled: true,
      card_payment_state: 'pending',
      card_redirect_url: 'javascript:alert(1)',
    })
    await submitWithMethod(page, 'card')

    await expect(page.getByRole('link', { name: 'Продължи към плащане' })).toHaveCount(0)
    const browserState = await page.evaluate(() => ({
      url: location.href,
      local: Object.keys(localStorage),
      session: Object.keys(sessionStorage),
    }))
    expect(browserState.url).toBe(`${storefrontUrl}/checkout/success`)
    expect([...browserState.local, ...browserState.session].join(' ')).not.toMatch(
      /payment|idempotency|capability|checkout/i,
    )

    expect(await page.locator('body').innerText()).not.toContain('javascript:alert')
    const serialized = JSON.stringify(await fixtureState(request))
    expect(serialized).not.toMatch(/mc_payment_retry|mc_checkout_confirmation|Idempotency-Key/)
  })
})
