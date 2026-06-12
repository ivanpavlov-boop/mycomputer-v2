import type { ProductDetail } from '~/types/api'

export function useSeo() {
  const config = useRuntimeConfig()

  function product(product: ProductDetail) {
    const title = product.seo?.meta_title || product.name
    const description = product.seo?.meta_description || product.short_description || ''
    const canonical = `${config.public.siteUrl}/p/${product.slug}`

    useSeoMeta({
      title,
      description,
      ogTitle: title,
      ogDescription: description,
      ogType: 'product',
    })
    useHead({
      link: [{ rel: 'canonical', href: canonical }],
      script: [
        {
          type: 'application/ld+json',
          children: JSON.stringify({
            '@context': 'https://schema.org',
            ...product.structured_data,
          }),
        },
      ],
    })
  }

  function page(title: string, description = '', path = '/') {
    const canonical = `${config.public.siteUrl}${path}`
    useSeoMeta({
      title,
      description,
      ogTitle: title,
      ogDescription: description,
    })
    useHead({ link: [{ rel: 'canonical', href: canonical }] })
  }

  return { product, page }
}
