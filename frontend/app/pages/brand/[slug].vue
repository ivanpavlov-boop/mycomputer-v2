<template>
  <div>
    <Breadcrumbs :items="[{ label: brand?.name || 'Марка' }]" />
    <div class="container-page">
      <div class="mb-6 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
          <h1 class="text-3xl font-bold">{{ brand?.name }}</h1>
          <p v-if="brand?.description" class="mt-2 text-slate-600">{{ brand.description }}</p>
        </div>
        <SortSelect v-model="sort" />
      </div>
      <ProductGrid :products="products" />
      <Pagination :meta="productsResponse?.meta" @change="setPage" />
    </div>
  </div>
</template>

<script setup lang="ts">
const route = useRoute()
const router = useRouter()
const brands = useBrands()
const seo = useSeo()
const slug = computed(() => String(route.params.slug))
const sort = computed({ get: () => String(route.query.sort || 'newest'), set: (value) => router.push({ query: { ...route.query, sort: value } }) })
const { data: brandData } = await useAsyncData(`brand-${slug.value}`, () => brands.detail(slug.value))
const { data: productsResponse } = await useAsyncData(`brand-products-${slug.value}-${JSON.stringify(route.query)}`, () => brands.products(slug.value, route.query), { watch: [() => route.query] })
const brand = computed(() => brandData.value?.data)
const products = computed(() => productsResponse.value?.data || [])
function setPage(page: number) {
  router.push({ query: { ...route.query, page } })
}
watchEffect(() => {
  if (brand.value) seo.page(brand.value.meta_title || brand.value.name, brand.value.meta_description || '', `/brand/${brand.value.slug}`)
})
</script>
