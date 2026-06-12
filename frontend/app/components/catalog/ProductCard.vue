<template>
  <article class="surface flex h-full flex-col overflow-hidden">
    <NuxtLink :to="`/p/${product.slug}`" class="block bg-white p-4">
      <div class="flex aspect-square items-center justify-center rounded-md bg-slate-100">
        <NuxtImg
          v-if="product.primary_image?.path"
          :src="imageSrc(product.primary_image.path)"
          :alt="product.primary_image.alt_text || product.name"
          class="h-full w-full object-contain"
          loading="lazy"
        />
        <span v-else class="text-4xl text-slate-300">□</span>
      </div>
    </NuxtLink>
    <div class="flex flex-1 flex-col p-4">
      <NuxtLink :to="`/p/${product.slug}`" class="line-clamp-2 min-h-11 font-semibold hover:text-brand-700">
        {{ product.name }}
      </NuxtLink>
      <div class="mt-2 flex items-center justify-between gap-2">
        <AvailabilityBadge v-if="product.availability" :availability="product.availability" />
        <StockBadge v-else :status="product.stock_status" />
        <div class="flex items-center gap-3">
          <button
            class="text-xs font-semibold text-brand-700"
            :aria-pressed="compare.has(product.id)"
            @click="compare.toggle(product)"
          >
            {{ compare.has(product.id) ? 'Премахни' : 'Сравни' }}
          </button>
          <button
            class="text-lg leading-none"
            :class="wishlist.has(product.id) ? 'text-red-600' : 'text-slate-400'"
            :aria-label="wishlist.has(product.id) ? 'Премахни от любими' : 'Добави в любими'"
            @click="wishlist.toggle(product.id)"
          >
            ♥
          </button>
        </div>
      </div>
      <div class="mt-auto pt-4">
        <ProductPrice :product="product" />
        <BaseButton
          class="mt-3 w-full"
          :disabled="product.availability?.allow_purchase === false"
          @click="addToCart"
        >
          {{ product.availability?.allow_purchase === false ? 'Не е наличен' : 'Добави в количката' }}
        </BaseButton>
      </div>
    </div>
  </article>
</template>

<script setup lang="ts">
import type { ProductCard } from '~/types/api'

const props = defineProps<{ product: ProductCard }>()
const cart = useCartStore()
const compare = useCompareStore()
const wishlist = useWishlistStore()
const config = useRuntimeConfig()
const storageBase = computed(() => String(config.public.apiBaseUrl).replace(/\/api\/v1\/?$/, ''))
const imageSrc = (path: string) => path.startsWith('http') ? path : `${storageBase.value}/storage/${path}`

function addToCart() {
  if (props.product.availability?.allow_purchase === false) return
  cart.add(props.product)
}
</script>
