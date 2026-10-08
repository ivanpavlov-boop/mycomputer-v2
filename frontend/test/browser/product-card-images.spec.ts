import { expect, test } from '@playwright/test'
import sharp from 'sharp'
import { FIXTURE_PRODUCT } from './fixtures/cart-fixture.mjs'
import { fixtureUrl, resetFixture } from './helpers'

test.beforeEach(async ({ request, page }) => {
  await resetFixture(request, { catalog_images: true })
  await page.route('https://apcom.shop/**', async route => {
    const name = new URL(route.request().url()).pathname.split('/').pop()
    await route.fulfill({ response: await request.get(`${fixtureUrl}/__test/gallery-image/${name}`) })
  })
})

test('catalog SSR and responsive cards preserve primary image, ALT and links', async ({ page, request }) => {
  const response = await page.goto('/catalog')
  const html = await response!.text()
  // SSR must already include variants and priorities, not depend on hydration.
  expect(html).toContain('/_ipx/cards/')
  expect(html.match(/fetchpriority="high"/g)).toHaveLength(1)
  const cards = page.locator('main article')
  await expect(cards).toHaveCount(15)
  const images = cards.locator('img')
  expect(await images.evaluateAll(nodes => nodes.map(n => n.getAttribute('loading'))))
    .toEqual(['eager', 'eager', ...Array(13).fill('lazy')])
  await expect(images.first()).toHaveAttribute('alt', '  Manual card ALT  ')
  await expect(images.nth(1)).toHaveAttribute('alt', FIXTURE_PRODUCT.localized?.name || FIXTURE_PRODUCT.name)
  await expect(cards.first().locator('a').first()).toHaveAttribute('href', '/p/laptops-1-0')
  expect(await images.first().getAttribute('srcset')).toMatch(/240.*320.*480.*640.*1280/)
  await expect.poll(() => images.first().evaluate(n => (n as HTMLImageElement).naturalWidth)).toBeGreaterThan(0)
  const src = await images.first().evaluate(n => (n as HTMLImageElement).currentSrc)
  expect(src).toContain('/_ipx/cards/')
  const image = await request.get(src)
  expect(image.headers()['content-type']).toBe('image/webp')
  const metadata = await sharp(await image.body()).metadata()
  expect(metadata.format).toBe('webp')
  // The original fixture is 1200x800; never crop or upscale it.
  expect(metadata.width! / metadata.height!).toBeCloseTo(1.5, 2)
  expect(metadata.width).toBeLessThanOrEqual(1200)
  await expect(page.locator('link[rel=preload][as=image]')).toHaveCount(0)
})

test('category requests are concurrent and preserve locale, query and pagination', async ({ page, request }) => {
  await page.goto('/en/c/laptops?sort=price_asc&price_max=150&filter[cpu]=intel')
  const cards = page.locator('main article')
  await expect(cards).toHaveCount(15)
  await expect(cards.first().locator('a').first()).toHaveAttribute('href', '/en/p/laptops-1-0')
  const observations = (await (await request.get(`${fixtureUrl}/__test/catalog-observations`)).json()).data
  const detail = observations.findIndex((r: any) => r.path === '/api/v1/categories/laptops' && r.phase === 'received')
  const products = observations.findIndex((r: any) => r.path.endsWith('/laptops/products') && r.phase === 'received')
  const released = observations.findIndex((r: any) => r.phase === 'detail-released')
  expect(detail).toBeGreaterThanOrEqual(0)
  expect(products).toBeGreaterThanOrEqual(0)
  expect(released).toBeGreaterThan(Math.max(detail, products))
  expect(observations[products].query).toContain('price_max=150')
  expect(observations[products].query).toContain('sort=price_asc')
  expect(observations[products].locale).toBe('en')
  await page.getByRole('button', { name: 'Напред', exact: true }).click()
  await expect(page).toHaveURL(/page=2/)
  await expect(cards.first().locator('a').first()).toHaveAttribute('href', '/en/p/laptops-2-0')
  await expect(page).toHaveURL(/price_max=150/)
  await page.getByRole('link', { name: 'Всички категории', exact: true }).click()
  await page.locator('main a[href="/en/c/keyboards"]').first().click()
  await expect(page).toHaveURL('/en/c/keyboards')
  await expect(cards.first().locator('a').first()).toHaveAttribute('href', '/en/p/keyboards-1-0')
  await expect(cards.first().locator('img')).toHaveAttribute('fetchpriority', 'high')
})

test('processing failure uses original and only original failure shows placeholder', async ({ page }) => {
  await page.route('**/_ipx/cards/**', route => route.fulfill({ status: 503, body: 'Synthetic processing failure' }))
  await page.route('https://apcom.shop/**/1.png', route => route.fulfill({ status: 404, body: '' }))
  await page.goto('/catalog')
  const cards = page.locator('main article')
  await expect(cards.first().locator('img')).toHaveAttribute('src', 'https://apcom.shop/media/catalog/product/0.png')
  await expect.poll(() => cards.first().locator('img').evaluate(n => (n as HTMLImageElement).naturalWidth)).toBeGreaterThan(0)
  await expect(cards.nth(1).locator('img')).toHaveCount(0)
  await expect(cards.nth(1)).toContainText('Няма снимка')
  await expect(cards.first().locator('img')).toHaveAttribute('alt', '  Manual card ALT  ')
})
