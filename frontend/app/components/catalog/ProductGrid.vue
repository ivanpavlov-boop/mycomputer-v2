<template>
  <div>
    <div v-if="products.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
      <CatalogProductCard
        v-for="(product, index) in products"
        :key="product.id"
        :product="product"
        :image-priority="prioritizeImages && index < 2 ? (index === 0 ? 'high' : 'eager') : 'lazy'"
      />
    </div>
    <UiEmptyState v-else />
  </div>
</template>

<script setup lang="ts">
import type { ProductCard } from '~/types/api'

defineProps<{ products: ProductCard[]; prioritizeImages?: boolean }>()
</script>
