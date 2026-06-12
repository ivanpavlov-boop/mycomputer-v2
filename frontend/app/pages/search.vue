<template>
  <div>
    <Breadcrumbs :items="[{ label: 'Търсене' }]" />
    <div class="container-page">
      <div class="mb-6 grid gap-4 md:grid-cols-[1fr_220px]">
        <SearchBar />
        <SortSelect v-model="sort" />
      </div>
      <SearchSuggestions v-if="suggestions.length" class="mb-6" :suggestions="suggestions" />
      <ProductGrid :products="products" />
      <Pagination :meta="result?.data.products.meta" @change="setPage" />
    </div>
  </div>
</template>

<script setup lang="ts">
const route = useRoute()
const router = useRouter()
const search = useSearch()
const seo = useSeo()
const analytics = useAnalytics()
const sort = computed({ get: () => String(route.query.sort || 'newest'), set: (value) => router.push({ query: { ...route.query, sort: value } }) })
const { data: result } = await useAsyncData(`search-${JSON.stringify(route.query)}`, () => search.run(route.query), { watch: [() => route.query] })
const products = computed(() => result.value?.data.products.data || [])
const suggestions = computed(() => result.value?.data.suggestions || [])
watchEffect(() => {
  if (route.query.q) analytics.search(String(route.query.q), { results_count: products.value.length })
})
function setPage(page: number) {
  router.push({ query: { ...route.query, page } })
}
seo.page('Търсене', 'Търсене в каталога на mycomputer.bg.', '/search')
</script>
