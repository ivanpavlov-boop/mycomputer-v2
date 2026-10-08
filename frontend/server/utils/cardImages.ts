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

export function publicImageMaxAge(headers: Headers, now = Date.now()) {
  // Vary is deliberately conservative: do not share any source-dependent response.
  if (headers.has('set-cookie') || headers.has('vary') || headers.get('pragma')?.toLowerCase().includes('no-cache')) return 0
  const control = headers.get('cache-control') || ''
  if (/\b(private|no-store|no-cache)\b/i.test(control)) return 0
  const directives = control.split(',').map(value => value.trim()).filter(Boolean)
  if (directives.some(value => !/^(public|immutable|must-revalidate|proxy-revalidate|(s-maxage|max-age)=\d+)$/i.test(value))) return 0
  if (['max-age', 's-maxage'].some(name => directives.filter(value => value.toLowerCase().startsWith(`${name}=`)).length > 1)) return 0
  const maxAge = /(?:^|,)\s*s-maxage=(\d+)/i.exec(control) || /(?:^|,)\s*max-age=(\d+)/i.exec(control)
  let ttl = Math.min(300, maxAge ? Number(maxAge[1]) : 300)
  if (headers.has('expires') && !maxAge) {
    const expires = Date.parse(headers.get('expires')!)
    if (!Number.isFinite(expires)) return 0
    ttl = Math.min(ttl, Math.max(0, Math.floor((expires - now) / 1000)))
  }
  const age = headers.get('age') || '0'
  if (!/^\d+$/.test(age)) return 0
  return Math.max(0, ttl - Number(age))
}

export function createCardImageHandler(options: Options) {
  return defineEventHandler(async (event) => {
    setResponseHeader(event, 'Cache-Control', 'private, no-store')
    setResponseHeader(event, 'Content-Security-Policy', "default-src 'none'")
    setResponseHeader(event, 'X-Content-Type-Options', 'nosniff')
    try {
      const match = /^\/_ipx\/cards\/(\d+)\/([^?]+)$/.exec(event.path)
      if (!match || !cardImageWidths.includes(Number(match[1]))) throw new Error('Invalid variant')
      const source = cardSource(decodeURIComponent(match[2]!), options)
      // Never forward a user's headers/cookies/credentials to an image source.
      const response = await fetch(source, {
        redirect: 'error',
        credentials: 'omit',
        headers: { accept: 'image/webp,image/jpeg,image/png,image/avif' },
        signal: AbortSignal.timeout(5000),
      })
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
      const ttl = publicImageMaxAge(response.headers)
      setResponseHeader(event, 'Cache-Control', ttl ? `public, max-age=${ttl}, s-maxage=${ttl}` : 'private, no-store')
      setResponseHeader(event, 'Content-Type', 'image/webp')
      return data
    } catch {
      // Do not expose upstream URLs, response bodies or credentials in errors.
      throw createError({ statusCode: 502, statusMessage: 'Catalog image unavailable' })
    }
  })
}
