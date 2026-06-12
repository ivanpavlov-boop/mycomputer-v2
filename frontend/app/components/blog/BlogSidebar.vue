<template>
  <aside class="grid h-fit gap-6">
    <section class="surface p-4">
      <h2 class="font-bold">Категории</h2>
      <BlogCategoryList class="mt-3" :categories="categories" />
    </section>
    <section class="surface p-4">
      <h2 class="font-bold">Тагове</h2>
      <BlogTagList class="mt-3" :tags="tags" />
    </section>
  </aside>
</template>

<script setup lang="ts">
import type { BlogCategory, BlogTag } from '~/types/api'

defineProps<{ categories: BlogCategory[], tags: BlogTag[] }>()
</script>
