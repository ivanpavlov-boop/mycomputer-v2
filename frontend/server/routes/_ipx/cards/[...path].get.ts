import { lazyEventHandler } from 'h3'
import { useRuntimeConfig } from '#imports'
import { createCardImageHandler } from '../../../utils/cardImages'

export default lazyEventHandler(() => {
  const config = useRuntimeConfig()
  return createCardImageHandler({
    apiServerBaseUrl: String(config.apiServerBaseUrl),
    publicApiBaseUrl: String(config.public.apiBaseUrl),
    siteUrl: String(config.public.siteUrl),
    apcomBase: config.ipx?.alias?.['/product-gallery/apcom'],
  })
})
