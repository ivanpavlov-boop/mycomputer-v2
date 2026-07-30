<template>
  <footer class="mt-16 border-t border-slate-200 bg-slate-950 text-slate-200">
    <div class="container-page grid gap-8 py-10 md:grid-cols-2 xl:grid-cols-4">
      <div>
        <p class="text-lg font-bold text-white">mycomputer.bg</p>
        <p class="mt-3 text-sm text-slate-400">Модерен онлайн магазин за компютърна техника, компоненти и аксесоари.</p>
      </div>
      <div>
        <p class="font-semibold text-white">Навигация</p>
        <div class="mt-3 grid gap-2 text-sm">
          <NuxtLink
            v-for="item in storefrontPrimaryNavigation"
            :key="item.key"
            :to="localePath(item.path)"
          >
            {{ navigationItemLabel(item, currentLocale) }}
          </NuxtLink>
        </div>
      </div>
      <div>
        <p class="font-semibold text-white">Правна информация</p>
        <div class="mt-3 grid gap-2 text-sm">
          <NuxtLink
            v-for="item in storefrontLegalNavigation"
            :key="item.key"
            :to="item.path"
          >
            {{ item.labels.bg }}
          </NuxtLink>
        </div>
      </div>
      <div>
        <MarketingNewsletterSignup />
      </div>
    </div>
  </footer>
</template>

<script setup lang="ts">
import { normalizeStorefrontLocale } from '~/utils/locales'
import {
  navigationItemLabel,
  storefrontLegalNavigation,
  storefrontPrimaryNavigation,
} from '~/utils/storefrontRouteAvailability'

const localePath = useLocalePath()
const { locale } = useI18n()
const currentLocale = computed(() => normalizeStorefrontLocale(locale.value))
</script>
