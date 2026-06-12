<template>
  <article class="surface flex h-full flex-col overflow-hidden">
    <NuxtLink :to="`/blog/${post.slug}`" class="block">
      <div class="aspect-[16/9] bg-slate-100">
        <NuxtImg v-if="post.featured_image" :src="post.featured_image" :alt="post.title" class="h-full w-full object-cover" loading="lazy" />
      </div>
    </NuxtLink>
    <div class="flex flex-1 flex-col p-4">
      <p v-if="post.category" class="text-sm font-semibold text-brand-700">{{ post.category.name }}</p>
      <NuxtLink :to="`/blog/${post.slug}`" class="mt-2 text-lg font-bold hover:text-brand-700">{{ post.title }}</NuxtLink>
      <p v-if="post.excerpt" class="mt-2 line-clamp-3 text-sm text-slate-600">{{ post.excerpt }}</p>
      <p class="mt-auto pt-4 text-xs text-slate-500">{{ post.reading_time || 1 }} мин. четене</p>
    </div>
  </article>
</template>

<script setup lang="ts">
import type { BlogPost } from '~/types/api'

defineProps<{ post: BlogPost }>()
</script>
