<template>
  <nav v-if="localeLinks.length > 1" class="flex items-center gap-1" :aria-label="t('common.language')">
    <NuxtLink
      v-for="localeLink in localeLinks"
      :key="localeLink.code"
      :to="localeLink.url"
      class="rounded-md border px-2 py-1 text-xs font-semibold transition"
      :class="locale === localeLink.code
        ? 'border-brand-700 bg-brand-700 text-white'
        : 'border-slate-200 text-slate-700 hover:border-brand-300 hover:text-brand-700'"
      :aria-current="locale === localeLink.code ? 'page' : undefined"
      :aria-label="`${t('common.language')}: ${localeLink.name}`"
    >
      {{ localeLink.shortLabel }}
    </NuxtLink>
  </nav>
</template>

<script setup lang="ts">
import { storefrontLocales } from '~/utils/locales'
import {
  availableStorefrontLocales,
  isPublicStorefrontRoute,
} from '~/utils/storefrontRouteAvailability'

const { locale, t } = useI18n()
const switchLocalePath = useSwitchLocalePath()
const route = useRoute()
const { state } = useCommerceReleaseGate()

const localeLinks = computed(() => {
  const availableLocales = availableStorefrontLocales(route.fullPath, state.value)

  return storefrontLocales.flatMap((supportedLocale) => {
    if (!availableLocales.includes(supportedLocale.code)) {
      return []
    }

    const url = switchLocalePath(supportedLocale.code)

    return url && isPublicStorefrontRoute(url, state.value)
      ? [{ ...supportedLocale, url }]
      : []
  })
})
</script>
