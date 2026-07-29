export default defineNuxtRouteMiddleware(() => {
  throw createError({ statusCode: 404, statusMessage: 'Page Not Found' })
})
