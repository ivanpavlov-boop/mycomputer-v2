<template>
  <article class="surface flex h-full flex-col overflow-hidden">
    <NuxtLink :to="`/bundles/${bundle.slug}`" class="block bg-white p-4">
      <div class="flex aspect-[4/3] items-center justify-center rounded-md bg-slate-100">
        <NuxtImg
          v-if="bundle.image_path"
          :src="imageSrc(bundle.image_path)"
          :alt="bundle.name"
          class="h-full w-full object-contain"
          loading="lazy"
        />
        <span v-else class="text-4xl text-slate-300">▦</span>
      </div>
    </NuxtLink>
    <div class="flex flex-1 flex-col p-4">
      <NuxtLink :to="`/bundles/${bundle.slug}`" class="line-clamp-2 min-h-11 font-semibold hover:text-brand-700">
        {{ bundle.name }}
      </NuxtLink>
      <p v-if="bundle.short_description" class="mt-2 line-clamp-2 text-sm text-slate-600">
        {{ bundle.short_description }}
      </p>
      <div class="mt-auto pt-4">
        <div class="flex items-end justify-between gap-3">
          <div>
            <div class="text-xl font-bold text-brand-700">{{ formatPrice(bundle.price) }}</div>
            <div v-if="Number(bundle.savings) > 0" class="text-sm text-emerald-700">
              Спестявате {{ formatPrice(bundle.savings) }}
            </div>
          </div>
          <NuxtLink :to="`/bundles/${bundle.slug}`" class="text-sm font-semibold text-brand-700">
            Виж
          </NuxtLink>
        </div>
      </div>
    </div>
  </article>
</template>

<script setup lang="ts">
import type { ProductBundle } from '~/types/api'

defineProps<{ bundle: ProductBundle }>()

const config = useRuntimeConfig()
const storageBase = computed(() => String(config.public.apiBaseUrl).replace(/\/api\/v1\/?$/, ''))
const imageSrc = (path: string) => path.startsWith('http') ? path : `${storageBase.value}/storage/${path}`
const formatPrice = (value: string | number) => `${Number(value).toFixed(2)} EUR`
</script>
