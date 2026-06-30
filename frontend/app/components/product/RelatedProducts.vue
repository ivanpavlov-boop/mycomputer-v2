<template>
  <section v-if="products.length" class="container-page mt-12">
    <h2 class="section-title">{{ title }}</h2>
    <CatalogProductGrid class="mt-5" :products="products" />
  </section>
</template>

<script setup lang="ts">
import type { ProductCard } from '~/types/api'

withDefaults(defineProps<{ products: ProductCard[]; title?: string }>(), { title: 'Свързани продукти' })
</script>
