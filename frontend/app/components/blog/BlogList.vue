<template>
  <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
    <BlogCard v-for="post in posts" :key="post.id" :post="post" />
  </div>
</template>

<script setup lang="ts">
import type { BlogPost } from '~/types/api'

defineProps<{ posts: BlogPost[] }>()
</script>
