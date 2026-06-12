<template>
  <div class="space-y-3">
    <div class="surface flex aspect-square items-center justify-center p-4">
      <NuxtImg
        v-if="activeImage"
        :src="imageSrc(activeImage.path)"
        :alt="activeImage.alt_text || productName"
        class="h-full w-full object-contain"
      />
      <span v-else class="text-5xl text-slate-300">▣</span>
    </div>
    <div v-if="images.length > 1" class="grid grid-cols-5 gap-2">
      <button
        v-for="image in images"
        :key="image.path"
        class="rounded-md border bg-white p-2"
        :class="activeImage?.path === image.path ? 'border-brand-500' : 'border-slate-200'"
        @click="activeImage = image"
      >
        <NuxtImg :src="imageSrc(image.path)" :alt="image.alt_text || productName" class="aspect-square w-full object-contain" loading="lazy" />
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import type { ProductImage } from '~/types/api'

const props = defineProps<{ images: ProductImage[]; productName: string }>()
const activeImage = ref<ProductImage | null>(props.images[0] || null)
const config = useRuntimeConfig()
const storageBase = computed(() => String(config.public.apiBaseUrl).replace(/\/api\/v1\/?$/, ''))
const imageSrc = (path: string) => path.startsWith('http') ? path : `${storageBase.value}/storage/${path}`
</script>
