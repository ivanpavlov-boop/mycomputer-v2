import type { CommerceReleaseState } from '../composables/useCommerceReleaseGate'
import {
  localizedStorefrontPath,
  storefrontLocales,
  stripStorefrontLocalePrefix,
  type StorefrontLocale,
} from './locales'

export interface StorefrontNavigationItem {
  key: string
  path: string
  labels: Record<StorefrontLocale, string>
}

export interface NormalizedStorefrontTarget {
  locale: StorefrontLocale
  path: string
}

export const storefrontPrimaryNavigation: StorefrontNavigationItem[] = [
  {
    key: 'products',
    path: '/catalog',
    labels: { bg: 'Продукти', en: 'Products' },
  },
  {
    key: 'categories',
    path: '/categories',
    labels: { bg: 'Категории', en: 'Categories' },
  },
]

export const storefrontLegalNavigation: StorefrontNavigationItem[] = [
  {
    key: 'terms',
    path: '/obshti-usloviya',
    labels: { bg: 'Общи условия', en: 'Terms and conditions' },
  },
  {
    key: 'privacy',
    path: '/politika-za-poveritelnost',
    labels: { bg: 'Политика за поверителност', en: 'Privacy policy' },
  },
]

const alwaysPublicPaths = new Set([
  '/',
  '/catalog',
  '/categories',
])

const bulgarianLegalPaths = new Set(
  storefrontLegalNavigation.map(item => item.path),
)

export function navigationItemLabel(
  item: StorefrontNavigationItem,
  locale: StorefrontLocale,
): string {
  return item.labels[locale]
}

export function isExternalStorefrontTarget(target: string): boolean {
  const value = target.trim()

  return value.startsWith('#')
    || value.startsWith('//')
    || /^[a-z][a-z\d+.-]*:/i.test(value)
}

export function normalizeStorefrontTarget(
  target: string,
): NormalizedStorefrontTarget | null {
  const value = target.trim()

  if (!value || isExternalStorefrontTarget(value)) {
    return null
  }

  const pathWithQuery = value.split('#', 1)[0] || '/'
  let path = pathWithQuery.split('?', 1)[0] || '/'

  path = path.startsWith('/') ? path : `/${path}`
  path = path.replace(/\/+$/, '') || '/'

  const locale: StorefrontLocale = path === '/en' || path.startsWith('/en/')
    ? 'en'
    : 'bg'

  return {
    locale,
    path: stripStorefrontLocalePrefix(path).replace(/\/+$/, '') || '/',
  }
}

export function isPublicStorefrontRoute(
  target: string,
  commerceState: CommerceReleaseState = 'closed',
): boolean {
  const normalized = normalizeStorefrontTarget(target)

  if (!normalized) {
    return false
  }

  const { locale, path } = normalized

  if (alwaysPublicPaths.has(path) || isPublicDynamicCatalogPath(path)) {
    return true
  }

  if (bulgarianLegalPaths.has(path)) {
    return locale === 'bg'
  }

  if (locale !== 'bg') {
    return false
  }

  if (path === '/cart' || path === '/checkout') {
    return commerceState === 'open'
  }

  if (path === '/checkout/success') {
    return commerceState === 'open' || commerceState === 'confirmation_only'
  }

  return false
}

export function isStorefrontNavigationTargetAvailable(
  target: string,
  commerceState: CommerceReleaseState = 'closed',
): boolean {
  if (isExternalStorefrontTarget(target)) {
    return true
  }

  const normalized = normalizeStorefrontTarget(target)

  return normalized?.path !== '/checkout'
    && normalized?.path !== '/checkout/success'
    && isPublicStorefrontRoute(target, commerceState)
}

export function availableStorefrontLocales(
  currentTarget: string,
  commerceState: CommerceReleaseState = 'closed',
): StorefrontLocale[] {
  const normalized = normalizeStorefrontTarget(currentTarget)

  if (!normalized) {
    return []
  }

  return storefrontLocales
    .map(locale => locale.code)
    .filter(locale => isPublicStorefrontRoute(
      localizedStorefrontPath(normalized.path, locale),
      commerceState,
    ))
}

function isPublicDynamicCatalogPath(path: string): boolean {
  return /^\/(?:c|p)\/[^/]+$/.test(path)
}
