<template>
  <div>
    <UiLoadingState v-if="pending" />
    <UiErrorState
      v-else-if="error || !product"
      title="Продуктът не е намерен"
      text="Продуктът може да не е публичен или вече да не е наличен."
    />
    <div v-else>
      <LayoutBreadcrumbs :items="[
        { label: product.category?.name || 'Категория', to: product.category ? `/c/${product.category.slug}` : undefined },
        { label: product.localized?.name || product.name },
      ]" />

      <section class="container-page grid gap-8 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
        <ProductGallery :images="product.images || []" :product-name="product.localized?.name || product.name" />

        <div class="space-y-5">
          <div>
            <div class="flex flex-wrap items-center gap-2 text-sm text-slate-500">
              <NuxtLink v-if="product.category" :to="localePath(`/c/${product.category.slug}`)" class="font-medium text-brand-700 hover:text-brand-800">
                {{ product.category.localized?.name || product.category.name }}
              </NuxtLink>
              <span v-if="product.category && product.brand" aria-hidden="true">·</span>
              <span v-if="product.brand" class="font-medium">{{ product.brand.name }}</span>
            </div>

            <h1 class="mt-2 text-3xl font-bold tracking-normal text-slate-950">{{ product.localized?.name || product.name }}</h1>

            <div class="mt-3 flex flex-wrap gap-3 text-sm text-slate-500">
              <span>SKU: {{ product.sku }}</span>
              <span v-if="product.ean">EAN: {{ product.ean }}</span>
              <span v-if="product.mpn">MPN: {{ product.mpn }}</span>
            </div>
          </div>

          <section class="surface space-y-3 p-4">
            <p class="text-sm font-semibold text-slate-700">Наличност</p>
            <ProductAvailabilityBadge v-if="product.availability" :availability="product.availability" />
            <ProductStockBadge v-else :status="product.stock_status" />
            <ProductAvailabilityInfo v-if="product.availability" :availability="product.availability" :quantity="product.quantity" />
          </section>

          <section class="surface p-4">
            <p class="text-sm font-semibold text-slate-700">Цена</p>
            <ProductPrice :product="product" />
          </section>

          <section class="surface grid gap-3 p-4 text-sm text-slate-700 sm:grid-cols-2">
            <p>Гаранция: {{ product.warranty_months || 24 }} месеца</p>
            <p v-if="product.weight">Тегло: {{ product.weight }} kg</p>
            <p v-if="product.brand">Бранд: {{ product.brand.name }}</p>
            <p v-if="product.category">Категория: {{ product.category.name }}</p>
          </section>

          <p v-if="product.localized?.short_description || product.short_description" class="text-slate-700">{{ product.localized?.short_description || product.short_description }}</p>
        </div>
      </section>

      <section class="container-page mt-10">
        <ProductTabs
          :description="product.localized?.description || product.description"
          :specification-groups="product.specification_groups || []"
        />
      </section>

      <ProductRelatedProducts :products="product.related_products || []" />
      <ProductAccessoryProducts :products="product.accessory_products || []" />
    </div>
  </div>
</template>

<script setup lang="ts">
import type { ProductDetail } from '~/types/api'
import { resourceData } from '~/utils/apiCollections'

const route = useRoute()
const products = useProducts()
const seo = useSeo()
const localePath = useLocalePath()
const { locale } = useI18n()

const slug = computed(() => String(route.params.slug || ''))
const { data, error, pending } = await useAsyncData(
  () => `product-${locale.value}-${slug.value}`,
  () => products.detail(slug.value),
  { watch: [slug, locale] },
)
const product = computed<ProductDetail | null>(() => resourceData<ProductDetail>(data.value))

watchEffect(() => {
  if (!product.value) return

  seo.product(product.value)
})
</script>
