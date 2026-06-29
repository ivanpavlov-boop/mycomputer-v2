<template>
  <div v-if="product">
    <Breadcrumbs :items="[
      { label: product.category?.name || 'Категория', to: product.category ? `/c/${product.category.slug}` : undefined },
      { label: product.name },
    ]" />

    <section class="container-page grid gap-8 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
      <ProductGallery :images="product.images" :product-name="product.name" />

      <div class="space-y-5">
        <div>
          <div class="flex flex-wrap items-center gap-2 text-sm text-slate-500">
            <NuxtLink v-if="product.category" :to="`/c/${product.category.slug}`" class="font-medium text-brand-700 hover:text-brand-800">
              {{ product.category.name }}
            </NuxtLink>
            <span v-if="product.category && product.brand" aria-hidden="true">·</span>
            <span v-if="product.brand" class="font-medium">{{ product.brand.name }}</span>
          </div>

          <h1 class="mt-2 text-3xl font-bold tracking-normal text-slate-950">{{ product.name }}</h1>

          <div class="mt-3 flex flex-wrap gap-3 text-sm text-slate-500">
            <span>SKU: {{ product.sku }}</span>
            <span v-if="product.ean">EAN: {{ product.ean }}</span>
            <span v-if="product.mpn">MPN: {{ product.mpn }}</span>
          </div>
        </div>

        <section class="surface space-y-3 p-4">
          <p class="text-sm font-semibold text-slate-700">Наличност</p>
          <AvailabilityBadge v-if="product.availability" :availability="product.availability" />
          <StockBadge v-else :status="product.stock_status" />
          <AvailabilityInfo v-if="product.availability" :availability="product.availability" :quantity="product.quantity" />
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

        <p v-if="product.short_description" class="text-slate-700">{{ product.short_description }}</p>
      </div>
    </section>

    <section class="container-page mt-10">
      <ProductTabs :description="product.description" :attributes="product.attributes" />
    </section>

    <RelatedProducts :products="product.related_products" />
    <AccessoryProducts :products="product.accessory_products" />
  </div>
</template>

<script setup lang="ts">
const route = useRoute()
const products = useProducts()
const seo = useSeo()
const slug = String(route.params.slug)
const { data } = await useAsyncData(`product-${slug}`, () => products.detail(slug))
const product = computed(() => data.value?.data)

watchEffect(() => {
  if (!product.value) return

  seo.product(product.value)
})
</script>
