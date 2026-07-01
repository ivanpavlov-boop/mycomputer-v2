export function useReadOnlyStorefrontRoute() {
  const route = useRoute()

  return computed(() => (
    route.path === '/catalog'
    || route.path === '/categories'
    || route.path.startsWith('/c/')
    || route.path.startsWith('/p/')
  ))
}
