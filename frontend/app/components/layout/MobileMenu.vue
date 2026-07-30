<template>
  <UiBaseModal :open="ui.mobileMenuOpen" @close="ui.mobileMenuOpen = false">
    <div class="flex items-center justify-between">
      <p class="font-semibold">Меню</p>
      <button class="rounded-md p-2 hover:bg-slate-100" @click="ui.mobileMenuOpen = false">✕</button>
    </div>
    <SearchBar class="mt-4" />
    <LayoutLanguageSwitcher class="mt-4" />
    <nav class="mt-5 grid gap-3 text-sm font-medium">
      <NuxtLink
        v-for="item in storefrontPrimaryNavigation"
        :key="item.key"
        :to="localePath(item.path)"
        @click="ui.mobileMenuOpen = false"
      >
        {{ navigationItemLabel(item, currentLocale) }}
      </NuxtLink>
      <NuxtLink v-if="canStartCheckout" to="/cart" @click="ui.mobileMenuOpen = false">Количка</NuxtLink>
    </nav>
  </UiBaseModal>
</template>

<script setup lang="ts">
import { normalizeStorefrontLocale } from '~/utils/locales'
import {
  navigationItemLabel,
  storefrontPrimaryNavigation,
} from '~/utils/storefrontRouteAvailability'

const ui = useUiStore()
const { canStartCheckout } = useCommerceReleaseGate()
const localePath = useLocalePath()
const { locale } = useI18n()
const currentLocale = computed(() => normalizeStorefrontLocale(locale.value))
</script>
