export default defineNuxtConfig({
  compatibilityDate: '2026-06-08',
  ssr: true,
  srcDir: 'app',
  modules: ['@pinia/nuxt', '@nuxtjs/tailwindcss', '@nuxt/image'],
  css: ['~/assets/css/main.css'],
  nitro: {
    externals: {
      trace: false,
    },
  },
  runtimeConfig: {
    apiServerBaseUrl: process.env.NUXT_API_SERVER_BASE_URL || process.env.NUXT_PUBLIC_API_BASE_URL || 'http://localhost:8000/api/v1',
    public: {
      apiBaseUrl: process.env.NUXT_PUBLIC_API_BASE_URL || '/api/v1',
      siteUrl: process.env.NUXT_PUBLIC_SITE_URL || 'http://localhost:3000',
      ga4Id: process.env.NUXT_PUBLIC_GA4_ID || '',
      metaPixelId: process.env.NUXT_PUBLIC_META_PIXEL_ID || '',
    },
  },
  app: {
    head: {
      htmlAttrs: { lang: 'bg' },
      titleTemplate: (title) => title ? `${title} | mycomputer.bg` : 'mycomputer.bg',
      meta: [
        { name: 'viewport', content: 'width=device-width, initial-scale=1' },
        { name: 'theme-color', content: '#0f172a' },
      ],
    },
  },
  image: {
    domains: ['localhost', '127.0.0.1'],
    format: ['webp', 'jpg', 'png'],
  },
  typescript: {
    strict: true,
  },
})
