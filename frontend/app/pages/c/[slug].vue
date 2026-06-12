<template>
  <div>
    <Breadcrumbs :items="[{ label: category?.name || 'Категория' }]" />
    <div class="container-page">
      <div class="mb-6 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
          <h1 class="text-3xl font-bold">{{ category?.name }}</h1>
          <p v-if="category?.description" class="mt-2 text-slate-600">{{ category.description }}</p>
        </div>
        <div class="flex gap-2">
          <BaseButton variant="secondary" class="lg:hidden" @click="ui.filtersOpen = true">Филтри</BaseButton>
          <SortSelect v-model="sort" />
        </div>
      </div>
      <div class="grid gap-6 lg:grid-cols-[280px_1fr]">
        <ProductFilters class="hidden lg:block" :filters="filters" :model="queryState" @update="setFilter" @reset="resetFilters" />
        <div>
          <LoadingState v-if="pending" />
          <ProductGrid v-else :products="products" />
          <Pagination :meta="productsResponse?.meta" @change="setPage" />
        </div>
      </div>
    </div>
    <BaseModal :open="ui.filtersOpen" @close="ui.filtersOpen = false">
      <ProductFilters :filters="filters" :model="queryState" @update="setFilter" @reset="resetFilters" />
    </BaseModal>
  </div>
</template>

<script setup lang="ts">
const route = useRoute()
const router = useRouter()
const ui = useUiStore()
const categories = useCategories()
const seo = useSeo()
const analytics = useAnalytics()
const slug = computed(() => String(route.params.slug))
const sort = computed({
  get: () => String(route.query.sort || 'newest'),
  set: (value) => setFilter('sort', value),
})
const queryState = computed(() => ({ ...route.query }))
const { data: categoryData } = await useAsyncData(`category-${slug.value}`, () => categories.detail(slug.value))
const { data: filterData } = await useAsyncData(`filters-${slug.value}`, () => categories.filters(slug.value))
const { data: productsResponse, pending } = await useAsyncData(`category-products-${slug.value}-${JSON.stringify(route.query)}`, () => categories.products(slug.value, route.query), { watch: [() => route.query] })
const category = computed(() => categoryData.value?.data)
const filters = computed(() => filterData.value?.data)
const products = computed(() => productsResponse.value?.data || [])

function setFilter(key: string, value: unknown) {
  router.push({ query: { ...route.query, [key]: value, page: undefined } })
}
function setPage(page: number) {
  router.push({ query: { ...route.query, page } })
}
function resetFilters() {
  router.push({ query: {} })
}
watchEffect(() => {
  if (category.value) {
    seo.page(category.value.meta_title || category.value.name, category.value.meta_description || '', `/c/${category.value.slug}`)
    analytics.track('ViewCategory', { category_id: category.value.id, category_slug: category.value.slug, category_name: category.value.name }, 'internal')
  }
})
</script>
