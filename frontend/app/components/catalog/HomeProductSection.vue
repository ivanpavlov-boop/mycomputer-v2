<template>
  <section v-if="products.length" class="container-page mt-12">
    <h2 class="section-title">{{ title }}</h2>
    <ProductGrid class="mt-5" :products="products" />
  </section>
</template>

<script setup lang="ts">
import type { ProductCard } from '~/types/api'

defineProps<{ title: string; products: ProductCard[] }>()
</script>
