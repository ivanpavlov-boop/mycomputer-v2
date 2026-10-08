import { expect, test } from '@playwright/test'
import sharp from 'sharp'
import { FIXTURE_PRODUCT } from './fixtures/cart-fixture.mjs'
import { fixtureUrl, resetFixture } from './helpers'

const images = Array.from({ length: 8 }, (_, index) => ({
  path: `https://apcom.shop/media/catalog/product/${index}.png`,
  alt_text: `Изглед ${index + 1}`,
  is_primary: index === 0,
  sort_order: index,
}))
let pageErrors: string[] = []

test.beforeEach(async ({ request, page }) => {
  pageErrors = []
  page.on('pageerror', error => pageErrors.push(error.message))
  await resetFixture(request, { product_images: images })
  // Original images also stay local; this suite never calls the external host.
  await page.route('https://apcom.shop/media/catalog/product/*.png', async (route) => {
    const filename = new URL(route.request().url()).pathname.split('/').pop()
    const response = await request.get(`${fixtureUrl}/__test/gallery-image/${filename}`)
    await route.fulfill({ response })
  })
})

test.afterEach(() => expect(pageErrors).toEqual([]))

test('SSR gallery loads small thumbnails and preserves full image selection', async ({ page, request }) => {
  await page.goto(`/p/${FIXTURE_PRODUCT.slug}`)
  const gallery = page.locator('section.container-page').first().locator('div.space-y-3').first()
  const main = gallery.locator('.surface img')
  const thumbnails = gallery.locator('button img')

  await expect(thumbnails).toHaveCount(8)
  await expect(main).toHaveAttribute('loading', 'eager')
  await expect(main).toHaveAttribute('fetchpriority', 'high')
  await expect(page.locator('link[rel="preload"][as="image"][href*="product/0.png"]')).toHaveCount(1)
  expect(await thumbnails.evaluateAll(nodes => nodes.map(node => node.getAttribute('loading'))))
    .toEqual(['eager', 'eager', 'eager', 'eager', 'eager', 'lazy', 'lazy', 'lazy'])
  await expect(thumbnails.first()).toBeVisible()
  await expect.poll(() => thumbnails.first().evaluate(node => (node as HTMLImageElement).naturalWidth)).toBeGreaterThan(0)

  const thumbnailUrl = await thumbnails.first().evaluate(node => (node as HTMLImageElement).currentSrc)
  const thumbnailResponse = await request.get(thumbnailUrl)
  expect(thumbnailResponse.ok()).toBe(true)
  const thumbnail = await thumbnailResponse.body()
  const metadata = await sharp(thumbnail).metadata()
  expect(metadata.format).toBe('webp')
  expect([160, 320]).toContain(metadata.width)
  expect(thumbnail.length).toBeLessThan((await (await request.get(`${fixtureUrl}/__test/gallery-image/0.png`)).body()).length)

  const nextOriginal = page.waitForResponse(response => response.url() === images[1]!.path)
  await gallery.locator('button').nth(1).focus()
  expect((await nextOriginal).ok()).toBe(true)
  await gallery.locator('button').nth(1).click()
  await expect(main).toHaveAttribute('alt', 'Изглед 2')
  await expect.poll(() => main.evaluate(node => (node as HTMLImageElement).naturalWidth)).toBeGreaterThan(0)
  expect(await main.evaluate(node => (node as HTMLImageElement).currentSrc)).toBe(images[1]!.path)
  await expect(thumbnails).toHaveCount(8)
})

test('a failed resized thumbnail falls back to its original without losing the image', async ({ page }) => {
  await page.route('**/_ipx/**', async (route) => {
    const url = route.request().url()
    if (url.includes('f_webp') && url.endsWith('/product-gallery/apcom/1.png')) {
      await route.fulfill({ status: 503, body: 'Synthetic resize failure' })
    } else {
      await route.continue()
    }
  })
  await page.goto(`/p/${FIXTURE_PRODUCT.slug}`)
  const gallery = page.locator('section.container-page').first().locator('div.space-y-3').first()
  const thumbnail = gallery.locator('button img').nth(1)
  await expect(thumbnail).toHaveAttribute('src', images[1]!.path)
  await expect.poll(() => thumbnail.evaluate(node => (node as HTMLImageElement).naturalWidth)).toBeGreaterThan(0)
  await expect(gallery.locator('button')).toHaveCount(8)
  await expect(gallery.locator('.surface img')).toHaveAttribute('alt', 'Изглед 1')
  await gallery.locator('button').nth(1).click()
  await expect(gallery.locator('.surface img')).toHaveAttribute('alt', 'Изглед 2')
})
