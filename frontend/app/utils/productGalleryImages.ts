// Only already-published catalog image URLs use this presentation alias.
// Keeping it out of image.domains leaves other storefront images unchanged.
export const productGalleryImageAliases = {
  '/product-gallery/apcom': 'https://apcom.shop/media/catalog/product',
}

export function productGalleryThumbnailSource(source: string): string {
  for (const [alias, origin] of Object.entries(productGalleryImageAliases)) {
    if (source.startsWith(`${origin}/`)) {
      return `${alias}${source.slice(origin.length)}`
    }
  }

  return source
}
