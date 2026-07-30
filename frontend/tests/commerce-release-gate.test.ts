import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { resolveCommerceReleaseState } from '../app/composables/useCommerceReleaseGate'

const frontendRoot = resolve(import.meta.dirname, '..')
const repositoryRoot = resolve(frontendRoot, '..')
const approvedManifest = {
  locale: 'bg',
  status: 'approved',
  terms: {
    route: '/obshti-usloviya',
    version: 'bg-terms-v1.0-2026-07-30',
    effective_date: '2026-07-30',
    source_sha256: 'a'.repeat(64),
  },
  privacy: {
    route: '/politika-za-poveritelnost',
    version: 'bg-privacy-v1.0-2026-07-30',
    effective_date: '2026-07-30',
    source_sha256: 'b'.repeat(64),
  },
  approval: {
    approved_by_role: 'project_owner',
    approved_at: '2026-07-30',
    legal_counsel_review: 'not_claimed',
  },
}

function source(path: string) {
  return readFileSync(resolve(frontendRoot, path), 'utf8')
}

function repoSource(path: string) {
  return readFileSync(resolve(repositoryRoot, path), 'utf8')
}

describe('controlled public commerce release gate', () => {
  it('uses the same strict four-state matrix as the backend', () => {
    expect(resolveCommerceReleaseState(false, false)).toBe('closed')
    expect(resolveCommerceReleaseState('false', 'false')).toBe('closed')
    expect(resolveCommerceReleaseState(false, true)).toBe('confirmation_only')
    expect(resolveCommerceReleaseState('false', 'true')).toBe('confirmation_only')
    expect(resolveCommerceReleaseState(true, true, true, approvedManifest)).toBe('open')
    expect(resolveCommerceReleaseState('true', 'true', 'true', approvedManifest)).toBe('open')
    expect(resolveCommerceReleaseState(true, true, false, approvedManifest)).toBe('invalid')
    expect(resolveCommerceReleaseState(true, true, true)).toBe('open')
    expect(resolveCommerceReleaseState(true, true, true, {})).toBe('invalid')
    expect(resolveCommerceReleaseState(true, true, true, {
      ...approvedManifest,
      terms: { ...approvedManifest.terms, effective_date: '2026-02-30' },
    })).toBe('invalid')
    expect(resolveCommerceReleaseState(true, true, true, {
      ...approvedManifest,
      privacy: { ...approvedManifest.privacy, source_sha256: 'INVALID' },
    })).toBe('invalid')
    expect(resolveCommerceReleaseState(true, true, true, {
      ...approvedManifest,
      approval: { ...approvedManifest.approval, approved_by_role: 'super_admin' },
    })).toBe('invalid')
    expect(resolveCommerceReleaseState(true, false)).toBe('invalid')
    expect(resolveCommerceReleaseState('true', 'false')).toBe('invalid')
    expect(resolveCommerceReleaseState('yes', 'true')).toBe('invalid')
    expect(resolveCommerceReleaseState(undefined, undefined)).toBe('invalid')
  })

  it('does not persist or emit release flags', () => {
    const gate = source('app/composables/useCommerceReleaseGate.ts')

    expect(gate).not.toContain('localStorage')
    expect(gate).not.toContain('sessionStorage')
    expect(gate).not.toContain('analytics')
    expect(gate).not.toContain('dataLayer')
    expect(gate).not.toContain('gtag')
    expect(gate).not.toContain('cookie')
  })

  it('gates cart, checkout, confirmation, English commerce, and recovery routes', () => {
    const entry = source('app/middleware/commerce-entry.ts')
    const confirmation = source('app/middleware/commerce-confirmation.ts')
    const recovery = source('app/middleware/cart-recovery-disabled.ts')
    const cart = source('app/pages/cart.vue')
    const checkout = source('app/pages/checkout/index.vue')
    const success = source('app/pages/checkout/success.vue')
    const recoveryPage = source('app/pages/cart/recover/[token].vue')

    expect(entry).toContain('!canStartCheckout.value')
    expect(entry).toContain("to.path === '/en/cart'")
    expect(entry).toContain("to.path === '/en/checkout'")
    expect(entry).toContain('statusCode: 404')
    expect(confirmation).toContain('!canShowConfirmation.value')
    expect(confirmation).toContain("to.path === '/en/checkout/success'")
    expect(recovery).toContain('statusCode: 404')
    expect(cart).toContain("middleware: 'commerce-entry'")
    expect(checkout).toContain("middleware: 'commerce-entry'")
    expect(success).toContain("middleware: 'commerce-confirmation'")
    expect(recoveryPage).toContain("middleware: 'cart-recovery-disabled'")
  })

  it('keeps all visible cart writers behind canStartCheckout', () => {
    const header = source('app/components/layout/AppHeader.vue')
    const mobile = source('app/components/layout/MobileMenu.vue')
    const layout = source('app/layouts/default.vue')
    const product = source('app/pages/p/[slug].vue')
    const bundle = source('app/components/bundles/BundlePriceBox.vue')
    const builder = source('app/pages/pc-builder/build/[id].vue')
    const cart = source('app/pages/cart.vue')

    expect(header).toContain('ClientOnly v-if="canStartCheckout"')
    expect(header).toContain('v-if="showCustomerNavigation" to="/compare"')
    expect(header).toContain("route.path === '/cart'")
    expect(header).toContain("route.path === '/checkout'")
    expect(mobile).toContain('v-if="canStartCheckout" to="/cart"')
    expect(mobile).toContain('v-if="showCustomerNavigation" to="/compare"')
    expect(layout).toContain('CartDrawer v-if="canStartCheckout"')
    expect(product).toContain('v-if="canStartCheckout"')
    expect(product).toContain('if (!canStartCheckout.value || !product.value)')
    expect(bundle).toContain('v-if="canStartCheckout"')
    expect(bundle).toContain('if (!canStartCheckout.value)')
    expect(builder).toContain('v-if="canStartCheckout"')
    expect(builder).toContain('if (!canStartCheckout.value || !buildData.value)')
    expect(cart).toContain('v-if="auth.isAuthenticated"')
    expect(cart).not.toContain("router.push('/login')")
  })

  it('renders only exact Bulgarian commerce routes through the Nginx template', () => {
    const nginx = repoSource('deploy/nginx/mycomputer.conf.template')
    const compose = repoSource('docker-compose.yml')
    const validator = repoSource('scripts/validate-commerce-nginx-gate.sh')

    expect(nginx).toContain('set $public_commerce_enabled "${PUBLIC_COMMERCE_ENABLED}";')
    expect(nginx).toContain('set $public_commerce_confirmation_enabled "${PUBLIC_COMMERCE_CONFIRMATION_ENABLED}";')
    expect(nginx).toContain('set $legal_content_approved "${LEGAL_CONTENT_APPROVED}";')
    expect(nginx).toContain('location = /obshti-usloviya {')
    expect(nginx).toContain('location = /politika-za-poveritelnost {')
    expect(nginx).toContain('return 308 /obshti-usloviya$is_args$args;')
    expect(nginx).toContain('return 308 /politika-za-poveritelnost$is_args$args;')
    expect(nginx).toContain('location = /en/terms { return 404; }')
    expect(nginx).toContain('location = /en/privacy { return 404; }')
    expect(nginx).toContain('location = /cart {')
    expect(nginx).toContain('location = /checkout {')
    expect(nginx).toContain('location = /checkout/success {')
    expect(nginx).toContain('location ^~ /cart/ { return 404; }')
    expect(nginx).toContain('location ^~ /checkout/ { return 404; }')
    expect(nginx).toContain('location = /en/cart { return 404; }')
    expect(nginx).toContain('location = /en/checkout { return 404; }')
    expect(nginx).toContain('location = /account { return 404; }')
    expect(nginx).toContain('location = /login { return 404; }')
    expect(nginx).toContain('location = /register { return 404; }')
    expect(nginx).toContain('location = /wishlist { return 404; }')
    expect(nginx).toContain('location = /compare { return 404; }')
    expect(nginx).toContain('return 308 /cart$is_args$args;')
    expect(nginx).toContain('return 308 /checkout$is_args$args;')
    expect(nginx).toContain('return 308 /checkout/success$is_args$args;')
    expect(compose).toContain("NGINX_ENVSUBST_FILTER: '^(PUBLIC_COMMERCE_ENABLED|PUBLIC_COMMERCE_CONFIRMATION_ENABLED|LEGAL_CONTENT_APPROVED)$'")
    expect(compose).toContain('NUXT_PUBLIC_COMMERCE_ENABLED: ${PUBLIC_COMMERCE_ENABLED:-false}')
    expect(compose).toContain('NUXT_PUBLIC_COMMERCE_CONFIRMATION_ENABLED: ${PUBLIC_COMMERCE_CONFIRMATION_ENABLED:-false}')
    expect(compose).toContain('NUXT_PUBLIC_LEGAL_CONTENT_APPROVED: ${LEGAL_CONTENT_APPROVED:-false}')
    expect(validator).toContain('validate_state closed false false false 404 404 404')
    expect(validator).toContain('validate_state confirmation-only false true false 404 404 200')
    expect(validator).toContain('validate_state open true true true 200 200 200')
    expect(validator).toContain('validate_state legal-unapproved true true false 404 404 200')
    expect(validator).toContain('validate_state invalid true false true 404 404 404')
    expect(validator).toContain('docker exec "$active_container" nginx -T')
  })

  it('keeps commerce pages private and non-indexable', () => {
    for (const path of [
      'app/pages/cart.vue',
      'app/pages/checkout/index.vue',
      'app/pages/checkout/success.vue',
    ]) {
      expect(source(path)).toContain('noindex, nofollow, noarchive')
    }

    expect(source('app/pages/checkout/success.vue')).toContain("name: 'referrer', content: 'no-referrer'")
    expect(repoSource('deploy/nginx/mycomputer.conf.template')).toContain('"no-store, private"')
  })
})
