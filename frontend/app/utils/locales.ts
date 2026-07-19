export const defaultStorefrontLocale = 'bg'
export const fallbackStorefrontLocale = 'bg'

export const storefrontLocales = [
  {
    code: 'bg',
    name: 'Български',
    shortLabel: 'BG',
    language: 'bg-BG',
    file: 'bg.ts',
  },
  {
    code: 'en',
    name: 'English',
    shortLabel: 'EN',
    language: 'en-GB',
    file: 'en.ts',
  },
] as const

export type StorefrontLocale = (typeof storefrontLocales)[number]['code']

export function isStorefrontLocale(value: unknown): value is StorefrontLocale {
  return typeof value === 'string' && storefrontLocales.some((locale) => locale.code === value)
}

export function normalizeStorefrontLocale(value: unknown): StorefrontLocale {
  return isStorefrontLocale(value) ? value : fallbackStorefrontLocale
}

export function stripStorefrontLocalePrefix(path: string): string {
  const normalizedPath = path.startsWith('/') ? path : `/${path}`

  if (normalizedPath === '/en') {
    return '/'
  }

  return normalizedPath.replace(/^\/en(?=\/)/, '') || '/'
}

export function localizedStorefrontPath(path: string, locale: StorefrontLocale): string {
  const unprefixedPath = stripStorefrontLocalePrefix(path)

  return locale === defaultStorefrontLocale
    ? unprefixedPath
    : unprefixedPath === '/'
      ? '/en'
      : `/en${unprefixedPath}`
}
