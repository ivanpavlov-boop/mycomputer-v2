import { createIPX } from 'ipx'
import { createError, defineEventHandler, setResponseHeader } from 'h3'
import { productGalleryImageAliases } from '../../app/utils/productGalleryImages'

export const cardImageWidths = [240, 320, 480, 640, 1280]
const maxSourceBytes = 10 * 1024 * 1024

type Options = {
  apiServerBaseUrl: string
  publicApiBaseUrl: string
  siteUrl: string
  apcomBase?: string
}

export function cardSource(source: string, options: Options) {
  const published = new URL(source, options.siteUrl)
  const publicApi = new URL(options.publicApiBaseUrl, options.siteUrl)
  if (published.username || published.password || published.search || published.hash) throw new Error('Non-public source')
  const pathname = decodeURIComponent(published.pathname)
  if (/[\\?#%\u0000-\u001f]/.test(pathname) || pathname.split('/').some(part => part === '..' || part === '.')) throw new Error('Invalid source path')
  const apcom = productGalleryImageAliases['/product-gallery/apcom']
  if (published.origin === 'https://apcom.shop' && pathname.startsWith('/media/catalog/product/')) {
    return `${options.apcomBase || apcom}/${pathname.slice('/media/catalog/product/'.length)}`
  }
  if ([publicApi.origin, new URL(options.siteUrl).origin].includes(published.origin) && pathname.startsWith('/storage/')) {
    return new URL(pathname, options.apiServerBaseUrl).href
  }
  throw new Error('Source outside public catalog image locations')
}

export function publicImageCachePolicy(headers: Headers, now = Date.now(), requestTime = now, responseTime = now) {
  const uncacheable = { browserMaxAge: 0, sharedMaxAge: 0 }
  if (![now, requestTime, responseTime].every(Number.isFinite) || requestTime > responseTime || responseTime > now) return uncacheable
  // Vary is deliberately conservative: do not share any source-dependent response.
  if (headers.has('set-cookie') || headers.has('vary') || headers.get('pragma')?.toLowerCase().includes('no-cache')) return uncacheable
  const control = headers.get('cache-control') || ''
  if (/\b(private|no-store|no-cache)\b/i.test(control)) return uncacheable
  const directives = control ? control.split(',').map(value => value.trim().toLowerCase()) : []
  if (directives.some(value => !/^(public|immutable|must-revalidate|proxy-revalidate|(s-maxage|max-age)=\d+)$/.test(value))) return uncacheable
  const names = directives.map(value => value.split('=')[0])
  if (new Set(names).size !== names.length) return uncacheable
  // Keep the reviewed stricter source permission, including max-age=0.
  const lifetimes = directives.filter(value => /^(s-maxage|max-age)=/.test(value)).map(value => Number(value.split('=')[1]))
  if (lifetimes.some(value => !Number.isSafeInteger(value) || value > 2147483647)) return uncacheable
  const httpDate = (value: string) => {
    const parsed = Date.parse(value)
    return Number.isFinite(parsed) && new Date(parsed).toUTCString() === value ? parsed : NaN
  }
  const date = headers.has('date') ? httpDate(headers.get('date')!) : responseTime
  const expires = headers.has('expires') ? httpDate(headers.get('expires')!) : undefined
  if (!Number.isFinite(date) || date > responseTime || (expires !== undefined && !Number.isFinite(expires))) return uncacheable
  const lifetime = lifetimes.length ? Math.min(...lifetimes) : expires !== undefined ? Math.max(0, (expires - date) / 1000) : 300
  const age = headers.get('age') ?? '0'
  if (!/^\d+$/.test(age) || !Number.isSafeInteger(Number(age)) || Number(age) > 2147483647) return uncacheable
  const currentAge = Math.max(responseTime - date, Number(age) * 1000 + responseTime - requestTime) + now - responseTime
  const remaining = Math.max(0, Math.floor(lifetime - currentAge / 1000))
  const longEligible = directives.includes('public') && (lifetimes.length > 0 || expires !== undefined)
  return { browserMaxAge: Math.min(300, remaining), sharedMaxAge: Math.min(longEligible ? 172800 : 300, remaining) }
}

export function publicImageMaxAge(headers: Headers, now = Date.now()) {
  return publicImageCachePolicy(headers, now).browserMaxAge
}

export function createCardImageHandler(options: Options) {
  return defineEventHandler(async (event) => {
    setResponseHeader(event, 'Cache-Control', 'private, no-store')
    setResponseHeader(event, 'X-Accel-Expires', '0')
    setResponseHeader(event, 'Content-Security-Policy', "default-src 'none'")
    setResponseHeader(event, 'X-Content-Type-Options', 'nosniff')
    try {
      const match = /^\/_ipx\/cards\/(\d+)\/([^?]+)$/.exec(event.path)
      if (!match || !cardImageWidths.includes(Number(match[1]))) throw new Error('Invalid variant')
      const source = cardSource(decodeURIComponent(match[2]!), options)
      // Never forward a user's headers/cookies/credentials to an image source.
      const requestTime = Date.now()
      const response = await fetch(source, {
        redirect: 'error',
        credentials: 'omit',
        headers: { accept: 'image/webp,image/jpeg,image/png,image/avif', 'cache-control': 'no-cache' },
        signal: AbortSignal.timeout(5000),
      })
      const responseTime = Date.now()
      if (response.status !== 200 || !/^image\/(jpeg|png|webp|avif)(?:;|$)/i.test(response.headers.get('content-type') || '')) {
        await response.body?.cancel()
        throw new Error('Invalid image response')
      }
      const reader = response.body!.getReader()
      const chunks: Uint8Array[] = []
      let length = 0
      while (true) {
        const next = await reader.read()
        if (next.done) break
        length += next.value.byteLength
        if (length > maxSourceBytes) {
          await reader.cancel()
          throw new Error('Image too large')
        }
        chunks.push(next.value)
      }
      const original = Buffer.concat(chunks)
      const ipx = createIPX({
        storage: { name: 'public-card', getMeta: () => ({}), getData: () => original },
        sharpOptions: { limitInputPixels: 40_000_000 },
      })
      const { data } = await ipx('/image', { w: match[1]!, f: 'webp', q: '80' }).process()
      const now = Date.now()
      const { browserMaxAge, sharedMaxAge } = publicImageCachePolicy(response.headers, now, requestTime, responseTime)
      setResponseHeader(event, 'Date', new Date(now).toUTCString())
      setResponseHeader(event, 'Cache-Control', sharedMaxAge ? `public, max-age=${browserMaxAge}, s-maxage=${sharedMaxAge}, must-revalidate` : 'private, no-store')
      // Nginx's relative s-maxage cache timer does not consume an upstream Age/Date.
      // An absolute deadline also survives a late HIT through another proxy cache.
      if (sharedMaxAge) setResponseHeader(event, 'X-Accel-Expires', `@${Math.floor(now / 1000) + sharedMaxAge}`)
      setResponseHeader(event, 'Content-Type', 'image/webp')
      return data
    } catch {
      // Do not expose upstream URLs, response bodies or credentials in errors.
      throw createError({ statusCode: 502, statusMessage: 'Catalog image unavailable' })
    }
  })
}
