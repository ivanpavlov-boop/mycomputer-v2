<template>
  <picture class="block">
    <source v-if="images.desktop" media="(min-width: 1200px)" :srcset="imageUrl(images.desktop)" />
    <source v-if="images.tablet" media="(min-width: 768px)" :srcset="imageUrl(images.tablet)" />
    <img
      class="h-full w-full object-cover"
      :src="imageUrl(images.mobile || images.tablet || images.desktop || '')"
      :alt="alt"
      loading="lazy"
    >
  </picture>
</template>

<script setup lang="ts">
defineProps<{
  images: { desktop?: string | null; tablet?: string | null; mobile?: string | null }
  alt: string
}>()

function imageUrl(path: string): string {
  if (!path) return ''
  if (path.startsWith('http') || path.startsWith('/')) return path
  return `/storage/${path}`
}
</script>
