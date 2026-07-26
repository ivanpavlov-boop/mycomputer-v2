import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import playwrightConfig from '../playwright.config'

const frontendRoot = resolve(import.meta.dirname, '..')
const repositoryRoot = resolve(frontendRoot, '..')

describe('Cart browser tooling contract', () => {
  it('uses the approved browser projects and deterministic local services', () => {
    expect(playwrightConfig.workers).toBe(1)
    expect(playwrightConfig.projects?.map(project => project.name)).toEqual([
      'chromium-desktop',
      'webkit-mobile',
    ])

    const configSource = readFileSync(resolve(frontendRoot, 'playwright.config.ts'), 'utf8')
    expect(configSource).toContain('http://127.0.0.1:3000')
    expect(configSource).toContain('http://127.0.0.1:4010')
    expect(configSource).toContain("NUXT_PUBLIC_CART_COOKIE_SECURE: 'false'")
    expect(configSource).not.toContain('computer2u.eu')
    expect(configSource).not.toContain('mycomputer.bg')

    const nuxtConfig = readFileSync(resolve(frontendRoot, 'nuxt.config.ts'), 'utf8')
    const cartSession = readFileSync(resolve(frontendRoot, 'app/composables/useCartSession.ts'), 'utf8')
    expect(nuxtConfig).toContain("process.env.NUXT_PUBLIC_CART_COOKIE_SECURE !== 'false'")
    expect(cartSession).toContain("String(config.public.cartCookieSecure) !== 'false'")
  })

  it('keeps Playwright test-only and exposes deterministic scripts', () => {
    const packageJson = JSON.parse(readFileSync(resolve(frontendRoot, 'package.json'), 'utf8'))

    expect(packageJson.dependencies).not.toHaveProperty('@playwright/test')
    expect(packageJson.devDependencies).toHaveProperty('@playwright/test')
    expect(packageJson.scripts).toMatchObject({
      'test:unit:ci': 'vitest run',
      'test:browser': 'playwright test',
      'test:browser:ci': 'playwright test --reporter=line',
    })
  })

  it('keeps public Cart and checkout routes disabled at Nginx', () => {
    const nginx = readFileSync(resolve(repositoryRoot, 'deploy/nginx/mycomputer.conf'), 'utf8')

    expect(nginx).toContain('location = /cart { return 404; }')
    expect(nginx).toContain('location ^~ /cart/ { return 404; }')
    expect(nginx).toContain('location = /checkout { return 404; }')
    expect(nginx).toContain('location ^~ /checkout/ { return 404; }')
    expect(nginx).toContain('return 404;')
  })

  it('runs unit, build, and separate browser checks in CI', () => {
    const workflow = readFileSync(resolve(repositoryRoot, '.github/workflows/ci.yml'), 'utf8')

    expect(workflow).toContain('npm run test:unit:ci')
    expect(workflow).toContain('npm run build')
    expect(workflow).toContain('frontend-browser:')
    expect(workflow).toContain('npx playwright install --with-deps chromium webkit')
    expect(workflow).toContain('npm run test:browser:ci')
    expect(workflow).toContain('actions/upload-artifact@v4')
  })
})
