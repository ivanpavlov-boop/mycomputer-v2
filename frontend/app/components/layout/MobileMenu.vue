<template>
  <BaseModal :open="ui.mobileMenuOpen" @close="ui.mobileMenuOpen = false">
    <div class="flex items-center justify-between">
      <p class="font-semibold">Меню</p>
      <button class="rounded-md p-2 hover:bg-slate-100" @click="ui.mobileMenuOpen = false">✕</button>
    </div>
    <SearchBar class="mt-4" />
    <LayoutLanguageSwitcher class="mt-4" />
    <nav class="mt-5 grid gap-3 text-sm font-medium">
      <NuxtLink to="/search" @click="ui.mobileMenuOpen = false">Продукти</NuxtLink>
      <NuxtLink v-if="!isReadOnlyStorefrontRoute" to="/compare" @click="ui.mobileMenuOpen = false">Сравнение</NuxtLink>
      <NuxtLink v-if="!isReadOnlyStorefrontRoute" to="/cart" @click="ui.mobileMenuOpen = false">Количка</NuxtLink>
      <NuxtLink to="/contacts" @click="ui.mobileMenuOpen = false">Контакти</NuxtLink>
    </nav>
  </BaseModal>
</template>

<script setup lang="ts">
const ui = useUiStore()
const isReadOnlyStorefrontRoute = useReadOnlyStorefrontRoute()
</script>
