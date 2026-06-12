<template>
  <article class="surface grid gap-3 p-3">
    <NuxtLink :to="`/p/${product.slug}`" class="font-semibold hover:text-brand-700">{{ product.name }}</NuxtLink>
    <p class="text-sm text-slate-500">{{ product.brand?.name }} · {{ product.category?.name }}</p>
    <ProductPrice :product="product" />
  </article>
</template>

<script setup lang="ts">
import type { ProductCard } from '~/types/api'

defineProps<{ product: ProductCard }>()
</script>
