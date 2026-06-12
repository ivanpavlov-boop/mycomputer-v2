<template>
  <header class="sticky top-0 z-40 border-b border-slate-200 bg-white/95 backdrop-blur">
    <div class="container-page flex h-16 items-center gap-4">
      <button class="rounded-md p-2 hover:bg-slate-100 lg:hidden" aria-label="Меню" @click="ui.mobileMenuOpen = true">
        ☰
      </button>
      <NuxtLink to="/" class="flex items-center gap-2 text-lg font-bold text-slate-950">
        <span class="rounded-md bg-brand-600 px-2 py-1 text-white">MC</span>
        mycomputer.bg
      </NuxtLink>
      <nav class="hidden items-center gap-5 text-sm font-medium text-slate-700 lg:flex">
        <NuxtLink to="/search">Продукти</NuxtLink>
        <NuxtLink to="/delivery">Доставка</NuxtLink>
        <NuxtLink to="/warranty">Гаранция</NuxtLink>
        <NuxtLink to="/leasing">Лизинг</NuxtLink>
      </nav>
      <SearchBar class="ml-auto hidden max-w-md flex-1 md:block" />
      <NuxtLink to="/compare" class="hidden text-sm font-semibold text-slate-700 hover:text-brand-700 sm:block">
        Сравни {{ compare.count ? `(${compare.count})` : '' }}
      </NuxtLink>
      <NuxtLink v-if="auth.isAuthenticated" to="/account/wishlist" class="hidden text-sm font-semibold text-slate-700 hover:text-brand-700 sm:block">
        Любими {{ wishlist.count ? `(${wishlist.count})` : '' }}
      </NuxtLink>
      <NuxtLink v-if="auth.isAuthenticated" to="/account" class="hidden text-sm font-semibold text-slate-700 hover:text-brand-700 sm:block">
        Профил
      </NuxtLink>
      <template v-else>
        <NuxtLink to="/login" class="hidden text-sm font-semibold text-slate-700 hover:text-brand-700 sm:block">Вход</NuxtLink>
        <NuxtLink to="/register" class="hidden text-sm font-semibold text-slate-700 hover:text-brand-700 sm:block">Регистрация</NuxtLink>
      </template>
      <CartButton />
    </div>
    <MobileMenu />
  </header>
</template>

<script setup lang="ts">
const ui = useUiStore()
const compare = useCompareStore()
const wishlist = useWishlistStore()
const auth = useAuthStore()
onMounted(async () => {
  await auth.fetchUser()
  await compare.load()
})
</script>
