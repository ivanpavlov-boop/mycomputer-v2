import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const frontendRoot = resolve(import.meta.dirname, '..')
const composable = readFileSync(
  resolve(frontendRoot, 'app/composables/usePaymentAttempts.ts'),
  'utf8',
)
const idempotency = readFileSync(
  resolve(frontendRoot, 'app/utils/paymentAttemptIdempotency.ts'),
  'utf8',
)

function sourceFiles(path: string): string[] {
  if (!existsSync(path)) {
    return []
  }

  return readdirSync(path)
    .flatMap((entry) => {
      const child = resolve(path, entry)

      return statSync(child).isDirectory()
        ? sourceFiles(child)
        : /\.(?:ts|vue)$/u.test(child) ? [child] : []
    })
}

describe('payment attempt sensitive data boundaries', () => {
  it('keeps retry keys in memory and out of storage, URLs, analytics, and logs', () => {
    const source = `${composable}\n${idempotency}`

    expect(source).not.toContain('localStorage')
    expect(source).not.toContain('sessionStorage')
    expect(source).not.toContain('URLSearchParams')
    expect(source).not.toContain('useAnalytics')
    expect(source).not.toContain('console.')
    expect(source).not.toContain('location.')
  })

  it('does not activate provider-specific payment or automatic retry flows', () => {
    expect(composable).not.toContain('/payments/initiate')
    expect(composable).not.toContain('card')
    expect(composable).not.toContain('leasing')
    expect(composable).not.toContain('setTimeout')
    expect(composable).not.toContain('setInterval')
  })

  it('does not mount a visible payment retry action', () => {
    const visibleSources = [
      ...sourceFiles(resolve(frontendRoot, 'app/components')),
      ...sourceFiles(resolve(frontendRoot, 'app/layouts')),
      ...sourceFiles(resolve(frontendRoot, 'app/pages')),
    ].map((path) => readFileSync(path, 'utf8')).join('\n')

    expect(visibleSources).not.toContain('usePaymentAttempts')
    expect(visibleSources).not.toContain('retryAccountOrder')
    expect(visibleSources).not.toContain('retryGuestOrder')
  })
})
