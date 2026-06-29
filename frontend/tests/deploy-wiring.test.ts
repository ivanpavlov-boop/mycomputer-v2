import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const repoRoot = resolve(__dirname, '..', '..')
const frontendRoot = resolve(__dirname, '..')

function repoSource(path: string) {
  return readFileSync(resolve(repoRoot, path), 'utf8')
}

function frontendSource(path: string) {
  return readFileSync(resolve(frontendRoot, path), 'utf8')
}

describe('frontend deploy wiring', () => {
  it('builds a Nuxt SSR container for production', () => {
    const dockerfile = frontendSource('Dockerfile')

    expect(dockerfile).toContain('FROM node:22-alpine AS build')
    expect(dockerfile).toContain('RUN npm ci')
    expect(dockerfile).toContain('RUN npm run build')
    expect(dockerfile).toContain('NITRO_HOST=0.0.0.0')
    expect(dockerfile).toContain('CMD ["node", ".output/server/index.mjs"]')
  })

  it('keeps nginx storefront routing narrow and leaves admin/API on Laravel', () => {
    const nginx = repoSource('deploy/nginx/mycomputer.conf')

    expect(nginx).toContain('proxy_pass http://frontend:3000;')
    expect(nginx).toContain('location = /catalog')
    expect(nginx).toContain('location = /categories')
    expect(nginx).toContain('location ^~ /c/')
    expect(nginx).toContain('location ^~ /p/')
    expect(nginx).toContain('location ^~ /_nuxt/')
    expect(nginx).toContain('location ^~ /admin')
    expect(nginx).toContain('location ^~ /api/')
    expect(nginx).toContain('location = /cart { return 404; }')
    expect(nginx).toContain('location = /checkout { return 404; }')
  })

  it('uses a private SSR API base URL and a browser-safe public API base URL', () => {
    const nuxtConfig = frontendSource('nuxt.config.ts')
    const useApi = frontendSource('app/composables/useApi.ts')

    expect(nuxtConfig).toContain('NUXT_API_SERVER_BASE_URL')
    expect(nuxtConfig).toContain('NUXT_PUBLIC_API_BASE_URL')
    expect(useApi).toContain('import.meta.server')
    expect(useApi).toContain('config.apiServerBaseUrl')
    expect(useApi).toContain('config.public.apiBaseUrl')
  })
})
