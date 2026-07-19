<template>
  <NuxtLink :to="localePath(`/c/${category.slug}`)" class="surface group block overflow-hidden p-4 hover:border-brand-200">
    <div class="flex aspect-[4/3] items-center justify-center rounded-md bg-slate-100 text-4xl text-brand-700">
      <span>{{ category.icon || '▦' }}</span>
    </div>
    <p class="mt-3 font-semibold group-hover:text-brand-700">{{ categoryName }}</p>
  </NuxtLink>
</template>

<script setup lang="ts">
import type { Category } from '~/types/api'

const props = defineProps<{ category: Category }>()
const localePath = useLocalePath()
const categoryName = computed(() => props.category.localized?.name || props.category.name)
</script>
