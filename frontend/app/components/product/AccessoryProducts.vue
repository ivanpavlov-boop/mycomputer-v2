<template>
  <RelatedProducts :products="products" title="Подходящи аксесоари" />
</template>

<script setup lang="ts">
import type { ProductCard } from '~/types/api'

defineProps<{ products: ProductCard[] }>()
</script>
