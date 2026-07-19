<template>
  <nav class="flex items-center gap-1" :aria-label="t('common.language')">
    <NuxtLink
      v-for="supportedLocale in storefrontLocales"
      :key="supportedLocale.code"
      :to="localeUrl(supportedLocale.code)"
      class="rounded-md border px-2 py-1 text-xs font-semibold transition"
      :class="locale === supportedLocale.code
        ? 'border-brand-700 bg-brand-700 text-white'
        : 'border-slate-200 text-slate-700 hover:border-brand-300 hover:text-brand-700'"
      :aria-current="locale === supportedLocale.code ? 'page' : undefined"
      :aria-label="`${t('common.language')}: ${supportedLocale.name}`"
    >
      {{ supportedLocale.shortLabel }}
    </NuxtLink>
  </nav>
</template>

<script setup lang="ts">
import { storefrontLocales, type StorefrontLocale } from '~/utils/locales'

const { locale, t } = useI18n()
const switchLocalePath = useSwitchLocalePath()
const localePath = useLocalePath()

function localeUrl(targetLocale: StorefrontLocale): string {
  return switchLocalePath(targetLocale) || localePath('/')
}
</script>
