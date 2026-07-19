import { stripStorefrontLocalePrefix } from '../utils/locales'

export function isReadOnlyStorefrontPath(path: string) {
  path = stripStorefrontLocalePrefix(path)

  return (
    path === '/catalog'
    || path === '/categories'
    || path.startsWith('/c/')
    || path.startsWith('/p/')
  )
}

export function useReadOnlyStorefrontRoute() {
  const route = useRoute()

  return computed(() => isReadOnlyStorefrontPath(route.path))
}
