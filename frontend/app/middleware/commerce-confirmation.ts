export default defineNuxtRouteMiddleware((to) => {
  const { canShowConfirmation } = useCommerceReleaseGate()

  if (to.path === '/en/checkout/success'
    || to.path.startsWith('/en/checkout/success/')
    || !canShowConfirmation.value) {
    throw createError({ statusCode: 404, statusMessage: 'Page Not Found' })
  }
})
