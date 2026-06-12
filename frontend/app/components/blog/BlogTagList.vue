<template>
  <div class="flex flex-wrap gap-2">
    <NuxtLink v-for="tag in tags" :key="tag.id" :to="`/blog/tag/${tag.slug}`" class="rounded bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-700 hover:bg-brand-100">
      {{ tag.name }}
    </NuxtLink>
  </div>
</template>

<script setup lang="ts">
import type { BlogTag } from '~/types/api'

defineProps<{ tags: BlogTag[] }>()
</script>
