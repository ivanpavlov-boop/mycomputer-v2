<template>
  <header class="sticky top-0 z-40 border-b border-slate-200 bg-white/95 backdrop-blur">
    <div class="container-page flex h-16 items-center gap-4">
      <button class="rounded-md p-2 hover:bg-slate-100 lg:hidden" aria-label="Меню" @click="ui.mobileMenuOpen = true">
        ☰
      </button>
      <NuxtLink :to="localePath('/')" class="flex items-center gap-2 text-lg font-bold text-slate-950">
        <span class="rounded-md bg-brand-600 px-2 py-1 text-white">MC</span>
        mycomputer.bg
      </NuxtLink>
      <nav class="hidden items-center gap-5 text-sm font-medium text-slate-700 lg:flex">
        <NuxtLink
          v-for="item in storefrontPrimaryNavigation"
          :key="item.key"
          :to="localePath(item.path)"
        >
          {{ navigationItemLabel(item, currentLocale) }}
        </NuxtLink>
      </nav>
      <SearchBar class="ml-auto hidden max-w-md flex-1 md:block" />
      <LayoutLanguageSwitcher class="hidden sm:flex" />
      <ClientOnly v-if="canStartCheckout">
        <CartButton />
        <template #fallback>
          <button
            class="relative rounded-md bg-slate-100 px-3 py-2 text-sm font-semibold"
            aria-busy="true"
            aria-label="Зареждаме количката"
            disabled
          >
            Зареждане…
          </button>
        </template>
      </ClientOnly>
    </div>
    <LayoutMobileMenu />
  </header>
</template>

<script setup lang="ts">
import {
  navigationItemLabel,
  storefrontPrimaryNavigation,
} from '~/utils/storefrontRouteAvailability'
import { normalizeStorefrontLocale } from '~/utils/locales'

const ui = useUiStore()
const auth = useAuthStore()
const { canStartCheckout } = useCommerceReleaseGate()
const localePath = useLocalePath()
const { locale } = useI18n()
const currentLocale = computed(() => normalizeStorefrontLocale(locale.value))

onMounted(() => auth.fetchUser())
</script>
