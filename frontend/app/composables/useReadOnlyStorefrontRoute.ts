export function isReadOnlyStorefrontPath(path: string) {
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
