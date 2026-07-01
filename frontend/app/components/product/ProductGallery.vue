<template>
  <div class="space-y-3">
    <div class="surface flex aspect-square items-center justify-center p-4">
      <NuxtImg
        v-if="activeVisibleImage"
        :src="imageSrc(activeVisibleImage.path)"
        :alt="activeVisibleImage.alt_text || productName"
        class="h-full w-full object-contain"
        @error="markImageFailed(activeVisibleImage.path)"
      />
      <div v-else class="flex h-full w-full flex-col items-center justify-center gap-2 text-center text-slate-400">
        <span class="text-5xl" aria-hidden="true">□</span>
        <span class="text-sm font-medium">Няма снимка</span>
      </div>
    </div>
    <div v-if="visibleImages.length > 1" class="grid grid-cols-5 gap-2">
      <button
        v-for="image in visibleImages"
        :key="image.path"
        type="button"
        class="rounded-md border bg-white p-2"
        :class="activeVisibleImage?.path === image.path ? 'border-brand-500' : 'border-slate-200'"
        @click="activeImage = image"
      >
        <NuxtImg
          :src="imageSrc(image.path)"
          :alt="image.alt_text || productName"
          class="aspect-square w-full object-contain"
          loading="lazy"
          @error="markImageFailed(image.path)"
        />
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import type { ProductImage } from '~/types/api'

const props = defineProps<{ images: ProductImage[]; productName: string }>()
const activeImage = ref<ProductImage | null>(props.images[0] || null)
const failedImagePaths = ref<Set<string>>(new Set())
const config = useRuntimeConfig()
const storageBase = computed(() => String(config.public.apiBaseUrl).replace(/\/api\/v1\/?$/, ''))
const imageSrc = (path: string) => path.startsWith('http') ? path : `${storageBase.value}/storage/${path}`
const visibleImages = computed(() => props.images.filter((image) => image.path && !failedImagePaths.value.has(image.path)))
const activeVisibleImage = computed(() => {
  if (activeImage.value && !failedImagePaths.value.has(activeImage.value.path)) {
    return activeImage.value
  }

  return visibleImages.value[0] || null
})

function markImageFailed(path: string) {
  failedImagePaths.value = new Set([...failedImagePaths.value, path])

  if (activeImage.value?.path === path) {
    activeImage.value = visibleImages.value[0] || null
  }
}

watch(() => props.images, (images) => {
  failedImagePaths.value = new Set()
  activeImage.value = images[0] || null
})
</script>
