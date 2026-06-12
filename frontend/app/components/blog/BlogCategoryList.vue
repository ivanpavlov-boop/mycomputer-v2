<template>
  <div class="grid gap-2 text-sm">
    <NuxtLink v-for="category in categories" :key="category.id" :to="`/blog/category/${category.slug}`" class="font-semibold text-slate-700 hover:text-brand-700">
      {{ category.name }}
    </NuxtLink>
  </div>
</template>

<script setup lang="ts">
import type { BlogCategory } from '~/types/api'

defineProps<{ categories: BlogCategory[] }>()
</script>
