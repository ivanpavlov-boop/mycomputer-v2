import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import {
  availableStorefrontLocales,
  isExternalStorefrontTarget,
  isPublicStorefrontRoute,
  isStorefrontNavigationTargetAvailable,
  normalizeStorefrontTarget,
} from '../app/utils/storefrontRouteAvailability'

const frontendRoot = resolve(import.meta.dirname, '..')

function source(path: string) {
  return readFileSync(resolve(frontendRoot, path), 'utf8')
}

describe('pre-launch storefront navigation availability', () => {
  it('normalizes locale prefixes, trailing slashes, queries, and fragments', () => {
    expect(normalizeStorefrontTarget('/obshti-usloviya/')).toEqual({
      locale: 'bg',
      path: '/obshti-usloviya',
    })
    expect(normalizeStorefrontTarget('/obshti-usloviya?source=footer#section-8')).toEqual({
      locale: 'bg',
      path: '/obshti-usloviya',
    })
    expect(normalizeStorefrontTarget('/en/catalog/?page=2#products')).toEqual({
      locale: 'en',
      path: '/catalog',
    })
  })

  it('keeps the read-only catalog routes available in Bulgarian and English', () => {
    for (const path of [
      '/',
      '/catalog',
      '/categories',
      '/c/laptops',
      '/p/testov-laptop',
      '/en',
      '/en/catalog',
      '/en/categories',
      '/en/c/laptops',
      '/en/p/testov-laptop',
    ]) {
      expect(isPublicStorefrontRoute(path, 'closed'), path).toBe(true)
    }
  })

  it('keeps Bulgarian legal routes public without exposing English counterparts', () => {
    for (const path of [
      '/obshti-usloviya',
      '/obshti-usloviya/?source=footer',
      '/politika-za-poveritelnost#rights',
    ]) {
      expect(isPublicStorefrontRoute(path, 'closed'), path).toBe(true)
    }

    expect(isPublicStorefrontRoute('/en/obshti-usloviya', 'open')).toBe(false)
    expect(isPublicStorefrontRoute('/en/politika-za-poveritelnost', 'open')).toBe(false)
    expect(availableStorefrontLocales('/obshti-usloviya', 'closed')).toEqual(['bg'])
    expect(availableStorefrontLocales('/politika-za-poveritelnost', 'closed')).toEqual(['bg'])
    expect(availableStorefrontLocales('/catalog', 'closed')).toEqual(['bg', 'en'])
    expect(availableStorefrontLocales('/en/p/testov-laptop', 'closed')).toEqual(['bg', 'en'])
  })

  it('uses the existing commerce states without exposing direct Checkout navigation', () => {
    expect(isPublicStorefrontRoute('/cart', 'closed')).toBe(false)
    expect(isPublicStorefrontRoute('/checkout', 'closed')).toBe(false)
    expect(isPublicStorefrontRoute('/checkout/success', 'closed')).toBe(false)

    expect(isPublicStorefrontRoute('/cart', 'confirmation_only')).toBe(false)
    expect(isPublicStorefrontRoute('/checkout', 'confirmation_only')).toBe(false)
    expect(isPublicStorefrontRoute('/checkout/success', 'confirmation_only')).toBe(true)

    expect(isPublicStorefrontRoute('/cart', 'open')).toBe(true)
    expect(isPublicStorefrontRoute('/checkout', 'open')).toBe(true)
    expect(isPublicStorefrontRoute('/checkout/success', 'open')).toBe(true)
    expect(isPublicStorefrontRoute('/en/cart', 'open')).toBe(false)
    expect(isPublicStorefrontRoute('/en/checkout', 'open')).toBe(false)

    expect(isStorefrontNavigationTargetAvailable('/cart', 'open')).toBe(true)
    expect(isStorefrontNavigationTargetAvailable('/checkout', 'open')).toBe(false)
    expect(isStorefrontNavigationTargetAvailable('/checkout/success', 'open')).toBe(false)
  })

  it('keeps pre-launch auth, account, comparison, and informational routes unavailable', () => {
    for (const path of [
      '/login',
      '/register',
      '/forgot-password',
      '/reset-password',
      '/account',
      '/account/orders',
      '/wishlist',
      '/compare',
      '/search',
      '/delivery',
      '/warranty',
      '/leasing',
      '/service',
      '/about',
      '/contacts',
      '/blog',
      '/bundles',
    ]) {
      expect(isPublicStorefrontRoute(path, 'open'), path).toBe(false)
    }
  })

  it('does not classify external, mail, telephone, or fragment targets as blocked internal routes', () => {
    for (const target of [
      'https://example.test/help',
      'mailto:sales@example.test',
      'tel:+359888000000',
      '#details',
    ]) {
      expect(isExternalStorefrontTarget(target), target).toBe(true)
      expect(isStorefrontNavigationTargetAvailable(target, 'closed'), target).toBe(true)
      expect(normalizeStorefrontTarget(target), target).toBeNull()
    }
  })

  it('uses an SSR-safe locale switch without fallback or client-only hiding', () => {
    const switcher = source('app/components/layout/LanguageSwitcher.vue')

    expect(switcher).toContain('v-if="localeLinks.length > 1"')
    expect(switcher).toContain('availableStorefrontLocales')
    expect(switcher).not.toContain("localePath('/')")
    expect(switcher).not.toContain('onMounted')
    expect(switcher).not.toContain('window.')
    expect(switcher).not.toContain('ClientOnly')
  })

  it('keeps desktop, mobile, and footer navigation on the shared contract', () => {
    const header = source('app/components/layout/AppHeader.vue')
    const mobile = source('app/components/layout/MobileMenu.vue')
    const footer = source('app/components/layout/AppFooter.vue')

    expect(header).toContain('storefrontPrimaryNavigation')
    expect(mobile).toContain('storefrontPrimaryNavigation')
    expect(footer).toContain('storefrontPrimaryNavigation')
    expect(footer).toContain('storefrontLegalNavigation')

    for (const component of [header, mobile, footer]) {
      expect(component).not.toMatch(/to="\/(?:login|register|account|wishlist|compare|search|delivery|warranty|leasing|service|about|contacts|blog)"/)
      expect(component).not.toContain('href="#"')
      expect(component).not.toContain('to="#"')
    }
  })
})
