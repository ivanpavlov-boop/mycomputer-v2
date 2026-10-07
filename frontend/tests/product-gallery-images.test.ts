import { createServer, type Server } from 'node:http'
import { createIPX, ipxHttpStorage } from 'ipx'
import sharp from 'sharp'
import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest'
import { productGalleryThumbnailSource } from '../app/utils/productGalleryImages'

vi.stubGlobal('defineNuxtConfig', (config: unknown) => config)
const { default: config } = await import('../nuxt.config')

afterAll(() => vi.unstubAllGlobals())

describe('gallery image sources', () => {
  it.each([
    '/storage/products/local.jpg',
    'https://other.example/product.jpg',
    'https://apcom.shop.evil.example/media/catalog/product/a.jpg',
    'https://apcom.shop@evil.example/media/catalog/product/a.jpg',
    'https://apcom.shop:8443/media/catalog/product/a.jpg',
    'http://apcom.shop/media/catalog/product/a.jpg',
    'https://apcom.shop/media/other/a.jpg',
  ])('keeps unsupported sources out of the gallery alias: %s', (source) => {
    expect(productGalleryThumbnailSource(source)).toBe(source)
  })

  it('does not opt other storefront components into remote optimization', () => {
    expect(config.image.domains).not.toContain('apcom.shop')
  })

  it.each([160, 320])('produces a smaller %ipx WebP from the configured alias without cropping', async (size) => {
    const original = await sharp({ create: { width: 1200, height: 800, channels: 3, background: '#4488cc' } })
      .png().toBuffer()
    const requested: string[] = []
    const ipx = createIPX({
      alias: config.image.alias,
      storage: { name: 'unused', getMeta: () => undefined, getData: () => undefined },
      httpStorage: {
        name: 'synthetic-catalog-image',
        getMeta: () => ({ maxAge: 300 }),
        getData: (id) => { requested.push(id); return original },
      },
    })
    const source = 'https://apcom.shop/media/catalog/product/a/fixture.png'
    const resized = await ipx(productGalleryThumbnailSource(source), {
      s: `${size}x${size}`, fit: 'inside', f: 'webp',
    }).process()
    const metadata = await sharp(resized.data).metadata()

    expect(requested).toEqual([source])
    expect(metadata.format).toBe('webp')
    expect(metadata.width).toBe(size)
    expect(metadata.height).toBe(Math.round(size * 800 / 1200))
    expect(resized.data.length).toBeLessThan(original.length)
  })
})

describe('IPX remote image boundary', () => {
  let server: Server
  let origin: string
  let redirectTargetRequests = 0

  beforeAll(async () => {
    server = createServer((request, response) => {
      if (request.url === '/redirect') {
        response.writeHead(302, { Location: `${origin}/redirect-target` })
      } else {
        redirectTargetRequests++
        response.writeHead(200)
      }
      response.end()
    })
    await new Promise<void>(resolve => server.listen(0, '127.0.0.1', resolve))
    const address = server.address()
    if (!address || typeof address === 'string') throw new Error('Missing fixture address')
    origin = `http://127.0.0.1:${address.port}`
  })

  afterAll(async () => {
    await new Promise<void>((resolve, reject) => server.close(error => error ? reject(error) : resolve()))
  })

  it('rejects unlisted remote hosts before any network request', async () => {
    const storage = ipxHttpStorage(config.image.ipx.http)
    await expect(storage.getData('https://unlisted.invalid/image.png'))
      .rejects.toMatchObject({ statusCode: 403, statusMessage: 'IPX_FORBIDDEN_HOST' })
  })

  it('does not follow redirects from allowed hosts', async () => {
    const storage = ipxHttpStorage(config.image.ipx.http)
    await expect(storage.getData(`${origin}/redirect`)).rejects.toThrow()
    expect(redirectTargetRequests).toBe(0)
  })
})
