<template>
  <ul v-if="visibleCategories.length" class="space-y-2" role="list">
    <li
      v-for="category in visibleCategories"
      :key="category.id"
      class="min-w-0"
    >
      <NuxtLink
        :to="localePath(`/c/${category.slug}`)"
        class="block truncate text-sm font-medium text-slate-700 transition hover:text-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
        :title="category.localized?.name || category.name"
      >
        {{ category.localized?.name || category.name }}
      </NuxtLink>

      <CatalogCategoryTree
        v-if="category.children?.length"
        :categories="category.children"
        :depth="depth + 1"
        :ancestor-ids="[...ancestorIds, category.id]"
        class="mt-2 ml-3 border-l border-slate-200 pl-3"
      />
    </li>
  </ul>
</template>

<script setup lang="ts">
import type { Category } from '~/types/api'

const props = withDefaults(defineProps<{
  categories: Category[]
  depth?: number
  ancestorIds?: number[]
}>(), {
  depth: 1,
  ancestorIds: () => [],
})

const localePath = useLocalePath()
const visibleCategories = computed(() => props.categories.filter(category => (
  Number.isInteger(category.id)
  && category.id > 0
  && !props.ancestorIds.includes(category.id)
)))
</script>
