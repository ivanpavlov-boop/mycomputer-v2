export default defineNuxtRouteMiddleware((to) => {
  const config = useRuntimeConfig()
  const { canStartCheckout } = useCommerceReleaseGate()
  const recoveryEnabled = config.public.abandonedCartRecoveryEnabled === true
    || config.public.abandonedCartRecoveryEnabled === 'true'

  if (to.path !== '/cart/recover' || !recoveryEnabled || !canStartCheckout.value) {
    throw createError({ statusCode: 404, statusMessage: 'Page Not Found' })
  }
})
