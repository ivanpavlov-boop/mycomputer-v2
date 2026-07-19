import type { ProductDetail } from '~/types/api'
import { localizedStorefrontPath, normalizeStorefrontLocale, type StorefrontLocale } from '~/utils/locales'

export function useSeo() {
  const config = useRuntimeConfig()
  const { locale } = useI18n()
  const englishLocaleIndexable = config.public.englishLocaleIndexable === true

  function absoluteUrl(path: string, targetLocale: StorefrontLocale): string {
    return new URL(localizedStorefrontPath(path, targetLocale), config.public.siteUrl).toString()
  }

  function localizedHead(path: string, scripts: Array<Record<string, unknown>> = []) {
    return () => {
      const activeLocale = normalizeStorefrontLocale(locale.value)
      const links = [
        { rel: 'canonical', href: absoluteUrl(path, activeLocale) },
        { rel: 'alternate', hreflang: 'bg', href: absoluteUrl(path, 'bg') },
        { rel: 'alternate', hreflang: 'x-default', href: absoluteUrl(path, 'bg') },
      ]

      if (englishLocaleIndexable) {
        links.push({ rel: 'alternate', hreflang: 'en', href: absoluteUrl(path, 'en') })
      }

      return {
        link: links,
        meta: activeLocale === 'en' && !englishLocaleIndexable
          ? [{ name: 'robots', content: 'noindex, follow' }]
          : [],
        script: scripts,
      }
    }
  }

  function product(product: ProductDetail) {
    const title = product.localized?.meta_title || product.seo?.meta_title || product.localized?.name || product.name
    const description = product.localized?.meta_description || product.seo?.meta_description || product.localized?.short_description || product.short_description || ''

    useSeoMeta({
      title,
      description,
      ogTitle: title,
      ogDescription: description,
      ogType: 'product',
    })
    useHead(localizedHead(`/p/${product.slug}`, [
      {
        type: 'application/ld+json',
        children: JSON.stringify({
          '@context': 'https://schema.org',
          ...product.structured_data,
        }),
      },
    ]))
  }

  function page(title: string, description = '', path = '/') {
    useSeoMeta({
      title,
      description,
      ogTitle: title,
      ogDescription: description,
    })
    useHead(localizedHead(path))
  }

  return { product, page }
}
