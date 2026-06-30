<template>
  <article class="surface flex h-full flex-col overflow-hidden border border-slate-200">
    <NuxtLink :to="`/p/${product.slug}`" class="block bg-white p-4">
      <div class="flex aspect-square items-center justify-center rounded-md bg-slate-100">
        <NuxtImg
          v-if="product.primary_image?.path"
          :src="imageSrc(product.primary_image.path)"
          :alt="product.primary_image.alt_text || product.name"
          class="h-full w-full object-contain"
          loading="lazy"
        />
        <div v-else class="flex h-full w-full flex-col items-center justify-center gap-2 text-center text-slate-400">
          <span class="text-4xl" aria-hidden="true">□</span>
          <span class="text-xs font-medium">Няма снимка</span>
        </div>
      </div>
    </NuxtLink>
    <div class="flex flex-1 flex-col gap-3 p-4">
      <NuxtLink :to="`/p/${product.slug}`" class="line-clamp-2 min-h-11 font-semibold hover:text-brand-700">
        {{ product.name }}
      </NuxtLink>
      <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
        <span v-if="product.category">{{ product.category.name }}</span>
        <span v-if="product.category && product.brand" aria-hidden="true">·</span>
        <span v-if="product.brand">{{ product.brand.name }}</span>
      </div>
      <div class="flex items-center gap-2">
        <ProductAvailabilityBadge v-if="product.availability" :availability="product.availability" />
        <ProductStockBadge v-else :status="product.stock_status" />
      </div>
      <div class="mt-auto pt-4">
        <ProductPrice :product="product" />
        <NuxtLink
          :to="`/p/${product.slug}`"
          class="mt-3 inline-flex w-full items-center justify-center rounded-md border border-brand-200 bg-brand-50 px-4 py-2 text-sm font-semibold text-brand-700 transition hover:bg-brand-100"
        >
          Виж продукта
        </NuxtLink>
      </div>
    </div>
  </article>
</template>

<script setup lang="ts">
import type { ProductCard } from '~/types/api'

defineProps<{ product: ProductCard }>()

const config = useRuntimeConfig()
const storageBase = computed(() => String(config.public.apiBaseUrl).replace(/\/api\/v1\/?$/, ''))
const imageSrc = (path: string) => path.startsWith('http') ? path : `${storageBase.value}/storage/${path}`
</script>
