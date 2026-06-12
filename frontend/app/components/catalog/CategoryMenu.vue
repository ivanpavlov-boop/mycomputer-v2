<template>
  <div class="surface p-4">
    <p class="mb-3 font-semibold">Категории</p>
    <div class="grid gap-2">
      <NuxtLink v-for="category in categories" :key="category.id" :to="`/c/${category.slug}`" class="rounded-md px-3 py-2 text-sm hover:bg-slate-100">
        {{ category.name }}
      </NuxtLink>
    </div>
  </div>
</template>

<script setup lang="ts">
import type { Category } from '~/types/api'

defineProps<{ categories: Category[] }>()
</script>
