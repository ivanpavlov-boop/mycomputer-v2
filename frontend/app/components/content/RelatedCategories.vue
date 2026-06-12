<template>
  <div v-if="categories?.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
    <CategoryCard v-for="category in categories" :key="category.id" :category="category" />
  </div>
</template>

<script setup lang="ts">
import type { Category } from '~/types/api'

defineProps<{ categories?: Category[] }>()
</script>
