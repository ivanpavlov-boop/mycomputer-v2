import { fileURLToPath } from 'node:url'
import { storefrontLocales } from './app/utils/locales'

const useApprovedLegalTestFixture = process.env.PLAYWRIGHT_TEST_BUILD === 'true'
  && process.env.LEGAL_CONTENT_TEST_FIXTURE === 'approved'
const legalManifestPath = fileURLToPath(new URL(
  useApprovedLegalTestFixture
    ? './test/browser/fixtures/approved-legal-content-manifest.json'
    : './app/data/legal/legal-content-manifest.json',
  import.meta.url,
))

export default defineNuxtConfig({
  compatibilityDate: '2026-06-08',
  ssr: true,
  srcDir: 'app',
  modules: ['@pinia/nuxt', '@nuxtjs/tailwindcss', '@nuxt/image', '@nuxtjs/i18n'],
  alias: {
    '#legal-content-manifest': legalManifestPath,
  },
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
      cartCookieSecure: process.env.NUXT_PUBLIC_CART_COOKIE_SECURE !== 'false',
      commerceEnabled: process.env.NUXT_PUBLIC_COMMERCE_ENABLED ?? 'false',
      commerceConfirmationEnabled: process.env.NUXT_PUBLIC_COMMERCE_CONFIRMATION_ENABLED ?? 'false',
      abandonedCartRecoveryEnabled: process.env.NUXT_PUBLIC_ABANDONED_CART_RECOVERY_ENABLED ?? 'false',
      legalContentApproved: process.env.NUXT_PUBLIC_LEGAL_CONTENT_APPROVED ?? 'false',
      englishLocaleIndexable: process.env.NUXT_PUBLIC_ENGLISH_LOCALE_INDEXABLE === 'true',
      ga4Id: process.env.NUXT_PUBLIC_GA4_ID || '',
      metaPixelId: process.env.NUXT_PUBLIC_META_PIXEL_ID || '',
    },
  },
  app: {
    head: {
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
  i18n: {
    defaultLocale: 'bg',
    strategy: 'prefix_except_default',
    detectBrowserLanguage: false,
    langDir: 'locales',
    locales: storefrontLocales.map(({ code, name, language, file }) => ({ code, name, language, file })),
    vueI18n: './i18n.config.ts',
  },
  typescript: {
    strict: true,
  },
})
