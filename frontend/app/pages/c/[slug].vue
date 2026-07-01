<template>
  <div>
    <Breadcrumbs :items="[
      { label: 'Категории', to: '/categories' },
      { label: category?.name || 'Категория' },
    ]" />

    <main class="container-page">
      <LoadingState v-if="categoryPending || productsPending" />

      <ErrorState
        v-else-if="categoryError"
        title="Категорията не е намерена"
        text="Разгледайте всички категории или опитайте с друга връзка."
      />

      <ErrorState
        v-else-if="productsError"
        title="Не успяхме да заредим продуктите"
        text="Моля, опитайте отново след малко."
      />

      <template v-else-if="category">
        <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
          <div>
            <p class="text-sm font-semibold uppercase tracking-wide text-brand-700">Продукти в категорията</p>
            <h1 class="mt-2 text-3xl font-bold tracking-normal text-slate-950">{{ category.name }}</h1>
            <p v-if="category.description" class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">{{ category.description }}</p>
          </div>

          <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <NuxtLink to="/categories" class="inline-flex items-center justify-center rounded-md border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-brand-300 hover:text-brand-700">
              Всички категории
            </NuxtLink>
            <SortSelect v-model="sort" />
          </div>
        </div>

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3 text-sm text-slate-600">
          <span v-if="productsMeta">
            Продукти: {{ productsMeta.total }}
          </span>
        </div>

        <ProductGrid v-if="products.length" :products="products" />

        <div v-else class="space-y-4">
          <EmptyState
            title="Няма активни продукти в тази категория."
            text="Разгледайте всички категории."
          />
          <NuxtLink to="/categories" class="inline-flex items-center justify-center rounded-md border border-brand-200 bg-brand-50 px-4 py-2 text-sm font-semibold text-brand-700 transition hover:bg-brand-100">
            Всички категории
          </NuxtLink>
        </div>

        <Pagination :meta="productsMeta" @change="setPage" />
      </template>

      <ErrorState
        v-else
        title="Категорията не е намерена"
        text="Разгледайте всички категории или опитайте с друга връзка."
      />
    </main>
  </div>
</template>

<script setup lang="ts">
import type { ProductCard } from '~/types/api'
import { paginatedResource } from '~/utils/apiCollections'
import { normalizeCatalogSort } from '~/utils/catalogSorts'
import { positiveInteger, routeQueryValue } from '~/utils/routeQuery'

const route = useRoute()
const router = useRouter()
const categories = useCategories()
const seo = useSeo()
const slug = computed(() => String(route.params.slug))
const sort = computed({
  get: () => normalizeCatalogSort(route.query.sort),
  set: (value) => updateQuery({ sort: normalizeCatalogSort(value), page: undefined }),
})

const categoryProductQuery = computed(() => {
  const query: Record<string, string | number> = {
    per_page: positiveInteger(route.query.per_page, 24),
    sort: sort.value,
  }

  const page = positiveInteger(route.query.page, 1)
  if (page > 1) {
    query.page = page
  }

  const search = routeQueryValue(route.query.search) || routeQueryValue(route.query.q)
  if (typeof search === 'string') {
    query.search = search
  }

  return query
})

const { data: categoryData, error: categoryError, pending: categoryPending } = await useAsyncData(
  `category-${slug.value}`,
  () => categories.detail(slug.value),
  { watch: [() => route.params.slug] },
)

const { data: productsResponse, error: productsError, pending: productsPending } = await useAsyncData(
  `category-products-${slug.value}`,
  () => categories.products(slug.value, categoryProductQuery.value),
  { watch: [() => route.params.slug, categoryProductQuery] },
)

const category = computed(() => categoryData.value?.data)
const normalizedProductsResponse = computed(() => paginatedResource<ProductCard>(productsResponse.value))
const products = computed<ProductCard[]>(() => normalizedProductsResponse.value.data)
const productsMeta = computed(() => normalizedProductsResponse.value.meta)

function updateQuery(next: Record<string, unknown>) {
  const merged = { ...route.query, ...next }
  const query: Record<string, string | string[]> = {}

  for (const [key, value] of Object.entries(merged)) {
    const normalized = key === 'sort' ? normalizeCatalogSort(value) : routeQueryValue(value)

    if (normalized !== undefined) {
      query[key] = normalized
    }
  }

  router.push({ query })
}

function setPage(page: number) {
  updateQuery({ page: page > 1 ? page : undefined })
}

watchEffect(() => {
  if (category.value) {
    seo.page(category.value.meta_title || category.value.name, category.value.meta_description || '', `/c/${category.value.slug}`)
  }
})
</script>
