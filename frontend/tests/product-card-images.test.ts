import { createServer, type Server } from 'node:http'
import { createApp, toNodeListener } from 'h3'
import sharp from 'sharp'
import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import { cardImageWidths, cardSource, createCardImageHandler, publicImageCachePolicy, publicImageMaxAge } from '../server/utils/cardImages'
import { getImage } from '../app/providers/card'

describe('public card images through real HTTP and IPX', () => {
  let upstream: Server
  let server: Server
  let origin: string
  let endpoint: string
  let requests: Array<{ path: string; cookie?: string; authorization?: string; extra?: string; cacheControl?: string }>
  let png: Buffer
  const listen = async (server: Server) => {
    await new Promise<void>(resolve => server.listen(0, '127.0.0.1', resolve))
    const address = server.address()
    if (!address || typeof address === 'string') throw new Error('No fixture address')
    return `http://127.0.0.1:${address.port}`
  }

  beforeAll(async () => {
    requests = []
    png = await sharp({ create: { width: 1600, height: 800, channels: 3, background: '#267845' } }).png().toBuffer()
    upstream = createServer((req, res) => {
      const name = req.url!.split('/').pop()!
      requests.push({ path: req.url!, cookie: req.headers.cookie, authorization: req.headers.authorization, extra: req.headers['x-api-key'] as string, cacheControl: req.headers['cache-control'] })
      const headers: Record<string, string> = { 'Content-Type': 'image/png', 'Cache-Control': 'public, max-age=120' }
      if (name === 'long') headers['Cache-Control'] = 'public, max-age=31536000'
      if (name === 'private') headers['Cache-Control'] = 'private, max-age=120'
      if (name === 'no-store') headers['Cache-Control'] = 'no-store'
      if (name === 'cookie') headers['Set-Cookie'] = 'synthetic=not-for-clients'
      if (name === 'vary') headers.Vary = 'Accept'
      if (name === 'html') headers['Content-Type'] = 'text/html'
      if (name === 'redirect') {
        res.writeHead(302, { Location: origin + '/must-not-follow' }).end()
        return
      }
      res.writeHead(name === 'error' ? 503 : 200, headers).end(name === 'corrupt' ? 'not an image' : png)
    })
    origin = await listen(upstream)
    const app = createApp().use(createCardImageHandler({
      apiServerBaseUrl: origin + '/api/v1', publicApiBaseUrl: '/api/v1',
      siteUrl: origin, apcomBase: origin + '/image',
    }))
    server = createServer(toNodeListener(app))
    endpoint = await listen(server)
  })

  afterAll(async () => {
    await Promise.all([server, upstream].map(server => new Promise<void>((resolve, reject) => server.close(error => error ? reject(error) : resolve()))))
  })

  const url = (name: string, width = 320) => endpoint + getImage(`https://apcom.shop/media/catalog/product/${name}`, { modifiers: { width } }).url

  it.each(cardImageWidths)('returns a real %ipx WebP without cropping', async width => {
    const response = await fetch(url('public', width))
    expect(response.status).toBe(200)
    expect(response.headers.get('content-type')).toBe('image/webp')
    const control = response.headers.get('cache-control')!
    const match = /^public, max-age=(\d+), s-maxage=(\d+), must-revalidate$/.exec(control)!
    expect(match).not.toBeNull()
    expect(Number(match[1])).toBeGreaterThan(0)
    expect(Number(match[1])).toBeLessThanOrEqual(120)
    expect(match[1]).toBe(match[2])
    expect(response.headers.get('x-accel-expires')).toBe(`@${Date.parse(response.headers.get('date')!) / 1000 + Number(match[2])}`)
    const bytes = Buffer.from(await response.arrayBuffer())
    const meta = await sharp(bytes).metadata()
    expect([meta.format, meta.width, meta.height]).toEqual(['webp', width, width / 2])
    expect(bytes.length).toBeLessThan(png.length)
  })

  it('does not forward user credentials or expose source cookies', async () => {
    const response = await fetch(url('cookie'), { headers: { Cookie: 'private=test', Authorization: 'Bearer synthetic', 'X-Api-Key': 'synthetic' } })
    expect(response.status).toBe(200)
    expect(response.headers.get('set-cookie')).toBeNull()
    expect(response.headers.get('cache-control')).toBe('private, no-store')
    expect(requests.at(-1)).toEqual({ path: '/image/cookie', cookie: undefined, authorization: undefined, extra: undefined, cacheControl: 'no-cache' })
  })

  it('uses independent browser/shared lifetimes and a fixed anonymous source revalidation request', async () => {
    const response = await fetch(url('long'), { headers: { 'Cache-Control': 'private, max-age=999', Cookie: 'synthetic=test' } })
    expect(response.status).toBe(200)
    expect(response.headers.get('cache-control')).toBe('public, max-age=300, s-maxage=172800, must-revalidate')
    expect(response.headers.get('x-accel-expires')).toBe(`@${Date.parse(response.headers.get('date')!) / 1000 + 172800}`)
    expect(requests.at(-1)?.cacheControl).toBe('no-cache')
    expect(requests.at(-1)?.cookie).toBeUndefined()
    await response.arrayBuffer()
  })

  it.each(['private', 'no-store', 'vary'])('does not permit caching a %s source', async name => {
    const response = await fetch(url(name))
    expect(response.status).toBe(200)
    expect(response.headers.get('cache-control')).toBe('private, no-store')
    expect(response.headers.get('x-accel-expires')).toBe('0')
  })

  it.each(['error', 'redirect', 'html', 'corrupt'])('rejects %s and never follows redirects', async name => {
    const response = await fetch(url(name))
    expect(response.status).toBe(502)
    expect(response.headers.get('cache-control')).toBe('private, no-store')
    expect(await response.text()).not.toContain('apcom.shop')
    expect(requests.some(r => r.path === '/must-not-follow')).toBe(false)
  })

  it('rejects arbitrary dimensions, query strings and unauthorized hosts before fetching', async () => {
    const count = requests.length
    for (const target of [
      url('public', 10000), url('public') + '?credential=synthetic',
      endpoint + getImage('https://unlisted.invalid/image.png').url,
      endpoint + getImage('https://apcom.shop@unlisted.invalid/media/catalog/product/a.png').url,
      endpoint + getImage('https://apcom.shop/media/catalog/product/a%3Fcredential=test').url,
      endpoint + getImage('https://apcom.shop/media/catalog/product/%252e%252e/image').url,
    ]) expect((await fetch(target)).status).toBe(502)
    expect(requests.length).toBe(count)
  })

  it('optimizes only the configured public storage namespace', async () => {
    const response = await fetch(endpoint + getImage('/storage/public').url)
    expect(response.status).toBe(200)
    expect(requests.at(-1)?.path).toBe('/storage/public')
    expect(() => cardSource('/api/v1/products', { apiServerBaseUrl: origin, publicApiBaseUrl: '/api/v1', siteUrl: origin })).toThrow()
  })
})

describe('cache lifetime policy', () => {
  it.each([
    [{}, 300],
    [{ 'Cache-Control': 'max-age=600' }, 300],
    [{ 'Cache-Control': 'max-age=120, s-maxage=30', Age: '4' }, 26],
    [{ 'Cache-Control': 'public, max-age=0' }, 0],
    [{ 'Cache-Control': 'private="Set-Cookie", max-age=100' }, 0],
    [{ 'Cache-Control': 'no-cache' }, 0],
    [{ 'Cache-Control': 'max-age=invalid' }, 0],
    [{ 'Cache-Control': 'max-age=100, max-age=0' }, 0],
    [{ Vary: '*' }, 0],
    [{ Vary: 'Cookie' }, 0],
    [{ 'Set-Cookie': 'synthetic=test' }, 0],
    [{ Pragma: 'no-cache' }, 0],
    [{ Expires: 'Thu, 01 Jan 1970 00:00:10 GMT' }, 10],
  ])('respects source directives %j', (headers, ttl) => {
    expect(publicImageMaxAge(new Headers(headers), 0)).toBe(ttl)
  })
})

describe('Option A independent freshness with fixed clocks', () => {
  const now = Date.parse('Fri, 09 Oct 2026 12:00:00 GMT')
  const date = (offset: number) => new Date(now + offset * 1000).toUTCString()
  const policy = (headers: Record<string, string>, current = now, request = current, response = current) =>
    publicImageCachePolicy(new Headers(headers), current, request, response)

  it.each([
    [{ 'Cache-Control': 'public, max-age=31536000' }, 300, 172800],
    [{ 'Cache-Control': 'public, max-age=600' }, 300, 600],
    [{ 'Cache-Control': 'public, max-age=120' }, 120, 120],
    [{ 'Cache-Control': 'public, max-age=600', Age: '350' }, 250, 250],
    [{ 'Cache-Control': 'public, max-age=1000, s-maxage=600', Age: '350' }, 250, 250],
    [{ 'Cache-Control': 'public, max-age=60, s-maxage=600' }, 60, 60],
    [{ 'Cache-Control': 'public, max-age=31536000, must-revalidate' }, 300, 172800],
    [{ 'Cache-Control': 'public, max-age=31536000, proxy-revalidate' }, 300, 172800],
    [{ 'Cache-Control': 'public', Expires: date(1000), Date: date(0) }, 300, 1000],
    [{ 'Cache-Control': 'public' }, 300, 300],
    [{}, 300, 300],
    [{ 'Cache-Control': 'max-age=31536000' }, 300, 300],
    [{ Expires: date(31536000) }, 300, 300],
    [{ 'Cache-Control': 'public, max-age=0', Expires: date(31536000) }, 0, 0],
    [{ 'Cache-Control': 'public, s-maxage=0, max-age=600' }, 0, 0],
  ])('honors the exact permission %j', (headers, browserMaxAge, sharedMaxAge) => {
    expect(policy(headers)).toEqual({ browserMaxAge, sharedMaxAge })
  })

  it.each([
    { 'Cache-Control': 'public, private, max-age=1000' },
    { 'Cache-Control': 'public, no-store, max-age=1000' },
    { 'Cache-Control': 'public, no-cache, max-age=1000' },
    { 'Cache-Control': 'public, max-age=1000, stale-while-revalidate=30' },
    { 'Cache-Control': 'public, max-age=1000, stale-if-error=30' },
    { 'Cache-Control': 'public, max-age=1000, max-age=0' },
    { 'Cache-Control': 'public, s-maxage=1000, s-maxage=0' },
    { 'Cache-Control': 'public, max-age=9007199254740992' },
    { 'Cache-Control': 'public, max-age=2147483648' },
    { 'Cache-Control': 'public, max-age=-1' },
    { 'Cache-Control': 'public,,max-age=1000' },
    { 'Cache-Control': 'public, max-age=oops' },
    { Age: '' }, { Age: '-1' }, { Age: '0, 1' }, { Age: '9007199254740992' }, { Age: '2147483648' },
    { Date: 'not-a-date' }, { Date: '0' }, { Date: date(1) },
    { Date: 'Thu, 09 Oct 2026 12:00:00 GMT' },
    { Expires: 'not-a-date' },
    { 'Set-Cookie': 'synthetic=test' }, { Vary: '*' }, { Vary: 'Accept' },
    { Pragma: 'no-cache' },
  ])('fails closed without a stale policy for %j', headers => {
    expect(policy({ 'Cache-Control': 'public, max-age=31536000', ...headers })).toEqual({ browserMaxAge: 0, sharedMaxAge: 0 })
  })

  it('consumes Age, header transfer, body transfer and processing before applying local caps', () => {
    expect(policy({ 'Cache-Control': 'public, max-age=600', Date: date(-20), Age: '350' }, now, now - 10000, now - 7000))
      .toEqual({ browserMaxAge: 240, sharedMaxAge: 240 })
    expect(policy({ 'Cache-Control': 'public, max-age=600', Date: date(-350), Age: '0' }, now, now - 10000, now - 7000))
      .toEqual({ browserMaxAge: 250, sharedMaxAge: 250 })
    expect(policy({ 'Cache-Control': 'public, max-age=10', Date: date(-10) })).toEqual({ browserMaxAge: 0, sharedMaxAge: 0 })
  })

  it('does not grant permission after backwards or unknown local clock observations', () => {
    for (const times of [[NaN, now, now], [now, now + 1, now], [now, now, now + 1]]) {
      expect(policy({ 'Cache-Control': 'public, max-age=31536000' }, ...times as [number, number, number]))
        .toEqual({ browserMaxAge: 0, sharedMaxAge: 0 })
    }
  })
})
