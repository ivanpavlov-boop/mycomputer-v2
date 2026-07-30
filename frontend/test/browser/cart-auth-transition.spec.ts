import { test, expect } from '@playwright/test'
import {
  cartCookie,
  configureFixture,
  fixtureState,
  resetFixture,
  seededSession,
  setCartCookie,
} from './helpers'

async function login(page: import('@playwright/test').Page, email = 'customer@example.test') {
  await page.goto('/login')
  await page.getByPlaceholder('Имейл').fill(email)
  await page.getByPlaceholder('Парола').fill('Fixture-Password-123!')
  await page.getByRole('button', { name: 'Вход', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Моят профил' })).toBeVisible()
}

test.describe('Authenticated Cart convergence', () => {
  test.beforeEach(async ({ context, request }) => {
    await resetFixture(request, {
      preset: 'product',
      seed_session_id: seededSession,
    })
    await setCartCookie(context)
  })

  test('converges a guest Cart to the canonical authenticated session', async ({ context, page, request }) => {
    await page.goto('/cart')
    await expect(page.getByRole('link', { name: 'Тестов лаптоп' })).toBeVisible()
    await login(page)

    const authenticatedCookie = await cartCookie(context)
    expect(authenticatedCookie?.value).toBe('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')

    await page.goto('/cart')
    await expect(page.getByRole('link', { name: 'Тестов лаптоп' })).toBeVisible()

    const state = await fixtureState(request)
    expect(state.requests).toContainEqual(expect.objectContaining({
      path: '/api/v1/cart',
      cart_session: seededSession,
      authorization_present: true,
    }))
    expect(JSON.stringify(state.requests)).not.toContain('fixture-token-user-a')
  })

  test('logout resolves a new anonymous Cart and does not expose the previous User Cart', async ({ context, page }) => {
    await login(page)
    await page.getByRole('button', { name: 'Изход' }).click()
    await expect(page.getByRole('heading', { name: 'Вход' })).toBeVisible()

    await page.goto('/cart')
    await expect(page.getByText('Количката е празна')).toBeVisible()
    expect((await cartCookie(context))?.value).not.toBe('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
  })

  test('a second User cannot inherit the first User Cart', async ({ context, page }) => {
    await login(page)
    await page.getByRole('button', { name: 'Изход' }).click()
    await login(page, 'customer-b@example.test')

    expect((await cartCookie(context))?.value).toBe('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb')
    await page.goto('/cart')
    await expect(page.getByText('Количката е празна')).toBeVisible()
    await expect(page.getByRole('link', { name: 'Тестов лаптоп' })).toHaveCount(0)
  })

  test('suppresses a delayed guest response and its analytics after login changes authority', async ({ context, page, request }) => {
    await page.goto('/cart')
    await page.waitForLoadState('networkidle')
    await configureFixture(request, { mutation_delay_ms: 1_000 })

    await page.getByLabel('Количество').fill('2')
    await page.getByRole('button', { name: 'Обнови', exact: true }).click()
    await page.evaluate(async () => {
      const auth = (document.querySelector('#__nuxt') as HTMLElement & {
        __vue_app__: {
          config: {
            globalProperties: {
              $pinia: {
                _s: Map<string, {
                  login: (payload: Record<string, string>) => Promise<void>
                }>
              }
            }
          }
        }
      }).__vue_app__.config.globalProperties.$pinia._s.get('auth')

      await auth?.login({
        email: 'customer@example.test',
        password: 'Fixture-Password-123!',
      })
    })
    await page.waitForTimeout(1_100)

    expect((await cartCookie(context))?.value).toBe('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
    await page.goto('/cart')
    await expect(page.getByLabel('Количество')).toHaveValue('1')

    const state = await fixtureState(request)
    expect(state.analytics.filter(event => event.event_name === 'add_to_cart')).toHaveLength(0)
  })
})
