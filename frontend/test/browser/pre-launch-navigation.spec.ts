import { expect, test, type Page } from '@playwright/test'
import { FIXTURE_CATEGORY, FIXTURE_PRODUCT } from './fixtures/cart-fixture.mjs'

const releaseState = process.env.COMMERCE_TEST_STATE ?? 'open'
const blockedPaths = [
  '/account',
  '/login',
  '/register',
  '/forgot-password',
  '/reset-password',
  '/wishlist',
  '/compare',
  '/search',
  '/about',
  '/contacts',
  '/delivery',
  '/warranty',
  '/leasing',
  '/service',
  '/blog',
  '/bundles',
  '/en/cart',
  '/en/checkout',
  '/en/obshti-usloviya',
  '/en/politika-za-poveritelnost',
]

function isBlockedPath(pathname: string): boolean {
  return blockedPaths.some(path => pathname === path || pathname.startsWith(`${path}/`))
}

async function expectNoBlockedAnchors(page: Page, selector = 'a[href]') {
  const hrefs = await page.locator(selector).evaluateAll(anchors => anchors.map(
    anchor => (anchor as HTMLAnchorElement).href,
  ))

  for (const href of hrefs) {
    const url = new URL(href)

    if (url.origin === new URL(page.url()).origin) {
      expect(isBlockedPath(url.pathname), href).toBe(false)
    }
  }
}

test('representative public pages render only reachable storefront navigation', async ({ page }) => {
  const runtimeMessages: string[] = []

  page.on('console', (message) => {
    if (message.type() === 'error' || /hydration/i.test(message.text())) {
      runtimeMessages.push(`${message.type()}: ${message.text()}`)
    }
  })
  page.on('pageerror', error => runtimeMessages.push(`pageerror: ${error.message}`))

  for (const path of [
    '/',
    '/catalog',
    '/categories',
    `/c/${FIXTURE_CATEGORY.slug}`,
    `/p/${FIXTURE_PRODUCT.slug}`,
    '/obshti-usloviya',
    '/politika-za-poveritelnost',
  ]) {
    const response = await page.goto(path)

    expect(response?.status(), path).toBe(200)
    await expectNoBlockedAnchors(page)

    const shellHrefs = await page.locator('header a[href], footer a[href]').evaluateAll(
      anchors => anchors.map(anchor => (anchor as HTMLAnchorElement).getAttribute('href')),
    )

    for (const href of shellHrefs.filter((value): value is string => Boolean(value))) {
      const target = new URL(href, page.url())
      const result = await page.request.get(target.toString())

      expect(result.status(), `${path} -> ${target.pathname}`).not.toBe(404)
    }
  }

  expect(runtimeMessages).toEqual([])
})

test('legal pages expose Bulgarian legal links without unavailable English counterparts', async ({ page }) => {
  for (const path of ['/obshti-usloviya', '/politika-za-poveritelnost']) {
    await page.goto(path)

    await expect(page.locator('a[href="/en/obshti-usloviya"]')).toHaveCount(0)
    await expect(page.locator('a[href="/en/politika-za-poveritelnost"]')).toHaveCount(0)
    await expect(page.locator('nav[aria-label="Език"]')).toHaveCount(0)
    await expect(page.locator('footer a[href="/obshti-usloviya"]')).toBeVisible()
    await expect(page.locator('footer a[href="/politika-za-poveritelnost"]')).toBeVisible()
  }
})

test('locale switch remains available on real bilingual catalog routes', async ({ page, isMobile }) => {
  await page.goto('/catalog')

  if (isMobile) {
    await page.getByRole('button', { name: 'Меню' }).click()
    await expect(page.locator('body > div.fixed.max-w-lg').getByRole('link', { name: 'Език: English' })).toBeVisible()
  } else {
    await expect(page.locator('header a[href="/en/catalog"]')).toBeVisible()
  }

  await page.goto('/en/catalog')

  if (isMobile) {
    await page.getByRole('button', { name: 'Меню' }).click()
    const mobileMenu = page.locator('body > div.fixed.max-w-lg')
    await expect(mobileMenu.getByRole('link', { name: 'Language: Български' })).toBeVisible()
    await expect(mobileMenu.getByRole('link', { name: 'Language: English' })).toHaveAttribute('aria-current', 'page')
  } else {
    await expect(page.locator('header a[href="/catalog"]')).toBeVisible()
    await expect(page.getByRole('link', { name: 'Language: English' })).toHaveAttribute('aria-current', 'page')
  }
})

test('desktop and mobile menus share the pre-launch route contract', async ({ page, isMobile }) => {
  await page.goto('/catalog')

  await expectNoBlockedAnchors(page, 'header a[href], footer a[href]')

  if (!isMobile) {
    await expect(page.getByRole('banner').getByRole('link', { name: 'Продукти', exact: true })).toBeVisible()
    await expect(page.getByRole('banner').getByRole('link', { name: 'Категории', exact: true })).toBeVisible()
    return
  }

  await page.getByRole('button', { name: 'Меню' }).click()
  const mobileMenu = page.locator('body > div.fixed.max-w-lg')
  await expect(mobileMenu.getByRole('link', { name: 'Продукти', exact: true })).toBeVisible()
  await expect(mobileMenu.getByRole('link', { name: 'Категории', exact: true })).toBeVisible()
  await expectNoBlockedAnchors(page, 'body > div.fixed.max-w-lg a[href]')

  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
  expect(overflow).toBeLessThanOrEqual(1)
})

test('commerce entry visibility follows the existing release gate only', async ({ page, isMobile }) => {
  await page.goto('/catalog')

  const desktopCart = page.locator('header button').filter({ hasText: /Количка|Зареждане/ })

  if (releaseState === 'open') {
    await expect(desktopCart).toBeVisible()
  } else {
    await expect(desktopCart).toHaveCount(0)
  }

  if (isMobile) {
    await page.getByRole('button', { name: 'Меню' }).click()
    const mobileCart = page.locator('body > div.fixed.max-w-lg a[href="/cart"]')

    if (releaseState === 'open') {
      await expect(mobileCart).toBeVisible()
    } else {
      await expect(mobileCart).toHaveCount(0)
    }
  }

  await expect(page.locator('a[href="/checkout"]')).toHaveCount(0)
  await expect(page.locator('a[href="/checkout/success"]')).toHaveCount(0)
})

test('keyboard focus never reaches an edge-blocked navigation target', async ({ page }) => {
  await page.goto('/')

  for (let step = 0; step < 20; step += 1) {
    await page.keyboard.press('Tab')

    const href = await page.evaluate(() => (
      document.activeElement instanceof HTMLAnchorElement
        ? document.activeElement.href
        : null
    ))

    if (href) {
      expect(isBlockedPath(new URL(href).pathname), href).toBe(false)
    }
  }
})
