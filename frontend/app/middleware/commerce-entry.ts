export default defineNuxtRouteMiddleware((to) => {
  const { canStartCheckout } = useCommerceReleaseGate()

  if (to.path === '/en/cart' || to.path.startsWith('/en/cart/')
    || to.path === '/en/checkout' || to.path.startsWith('/en/checkout/')
    || !canStartCheckout.value) {
    throw createError({ statusCode: 404, statusMessage: 'Page Not Found' })
  }
})
