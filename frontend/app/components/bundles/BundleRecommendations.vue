<template>
  <section v-if="bundles.length" class="mt-10">
    <div class="mb-4 flex items-center justify-between">
      <h2 class="text-2xl font-bold">Комплекти с този продукт</h2>
      <NuxtLink to="/bundles" class="text-sm font-semibold text-brand-700">Всички комплекти</NuxtLink>
    </div>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <BundleCard v-for="bundle in bundles" :key="bundle.id" :bundle="bundle" />
    </div>
  </section>
</template>

<script setup lang="ts">
import type { ProductBundle } from '~/types/api'

defineProps<{ bundles: ProductBundle[] }>()
</script>
