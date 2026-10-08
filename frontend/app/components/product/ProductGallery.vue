<template>
  <div class="space-y-3">
    <div class="surface flex aspect-square items-center justify-center p-4">
      <NuxtImg
        v-if="activeVisibleImage"
        :src="imageSrc(activeVisibleImage.path)"
        :alt="activeVisibleImage.alt_text?.trim() ? activeVisibleImage.alt_text : productName"
        class="h-full w-full object-contain"
        loading="eager"
        fetchpriority="high"
        :preload="{ fetchPriority: 'high' }"
        @error="markImageFailed(activeVisibleImage.path)"
      />
      <div v-else class="flex h-full w-full flex-col items-center justify-center gap-2 text-center text-slate-400">
        <span class="text-5xl" aria-hidden="true">□</span>
        <span class="text-sm font-medium">Няма снимка</span>
      </div>
    </div>
    <div v-if="visibleImages.length > 1" class="grid grid-cols-5 gap-2">
      <button
        v-for="(image, index) in visibleImages"
        :key="image.path"
        type="button"
        class="rounded-md border bg-white p-2"
        :class="activeVisibleImage?.path === image.path ? 'border-brand-500' : 'border-slate-200'"
        @click="activeImage = image"
        @mouseenter="preloadOriginal(image.path)"
        @focus="preloadOriginal(image.path)"
      >
        <NuxtImg
          :src="thumbnailSrc(image.path)"
          :provider="originalThumbnailPaths.has(image.path) ? 'none' : undefined"
          :alt="image.alt_text?.trim() ? image.alt_text : productName"
          class="aspect-square w-full object-contain"
          width="160"
          height="160"
          densities="x1 x2"
          fit="inside"
          format="webp"
          :loading="index < 5 ? 'eager' : 'lazy'"
          decoding="async"
          @error="markThumbnailFailed(image.path)"
        />
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import type { ProductImage } from '~/types/api'
import { productGalleryThumbnailSource } from '~/utils/productGalleryImages'

const props = defineProps<{ images: ProductImage[]; productName: string }>()
const initialImage = (images: ProductImage[]) => images.find(image => image.is_primary === true) || images[0] || null
const activeImage = ref<ProductImage | null>(initialImage(props.images))
const failedImagePaths = ref<Set<string>>(new Set())
const originalThumbnailPaths = ref<Set<string>>(new Set())
const preloadedImagePaths = new Set<string>()
const config = useRuntimeConfig()
const $img = useImage()
const storageBase = computed(() => String(config.public.apiBaseUrl).replace(/\/api\/v1\/?$/, ''))
const imageSrc = (path: string) => path.startsWith('http') ? path : `${storageBase.value}/storage/${path}`
const thumbnailSrc = (path: string) => originalThumbnailPaths.value.has(path)
  ? imageSrc(path)
  : productGalleryThumbnailSource(imageSrc(path))
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

function markThumbnailFailed(path: string) {
  if (!originalThumbnailPaths.value.has(path)) {
    // A failed resized variant must not discard a usable original image.
    originalThumbnailPaths.value = new Set([...originalThumbnailPaths.value, path])
    return
  }

  markImageFailed(path)
}

function preloadOriginal(path: string) {
  if (path === activeVisibleImage.value?.path || preloadedImagePaths.has(path)) return

  const image = new Image()
  image.fetchPriority = 'low'
  image.src = $img(imageSrc(path))
  preloadedImagePaths.add(path)
}

watch(() => props.images, (images) => {
  failedImagePaths.value = new Set()
  originalThumbnailPaths.value = new Set()
  preloadedImagePaths.clear()
  activeImage.value = initialImage(images)
})
</script>
