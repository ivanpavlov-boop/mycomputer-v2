<template>
  <div>
    <LayoutBreadcrumbs :items="[{ label: 'Каталог' }]" />

    <main class="container-page">
      <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
          <h1 class="text-3xl font-bold text-slate-950">Каталог</h1>
          <p class="mt-2 max-w-2xl text-sm text-slate-600">
            Разгледайте активните продукти в публичния каталог на COMPUTER2U.
          </p>
        </div>

        <div class="grid gap-3 sm:grid-cols-[minmax(220px,1fr)_220px] lg:w-[520px]">
          <UiBaseInput
            v-model="searchTerm"
            placeholder="Търсене по име, SKU, EAN или MPN"
            @keyup.enter="applySearch"
          />
          <CatalogSortSelect v-model="sort" />
        </div>
      </div>

      <div class="mb-4 flex flex-wrap items-center justify-between gap-3 text-sm text-slate-600">
        <span v-if="productsResponse?.meta">
          Намерени продукти: {{ productsResponse.meta.total }}
        </span>
        <UiBaseButton v-if="hasSearch" variant="secondary" @click="clearSearch">
          Изчисти търсенето
        </UiBaseButton>
      </div>

      <UiLoadingState v-if="pending" />
      <UiErrorState
        v-else-if="error"
        title="Не успяхме да заредим каталога"
        text="Моля, опитайте отново след малко."
      />
      <template v-else>
        <CatalogProductGrid v-if="products.length" :products="products" />
        <UiEmptyState
          v-else
          title="Няма активни продукти за показване."
          text="Променете търсенето или опитайте отново по-късно."
        />
        <CatalogPagination :meta="productsResponse?.meta" @change="setPage" />
      </template>
    </main>
  </div>
</template>

<script setup lang="ts">
import type { ProductCard } from '~/types/api'
import { collectionData } from '~/utils/apiCollections'

const route = useRoute()
const router = useRouter()
const productsApi = useProducts()
const seo = useSeo()

const supportedSorts = new Set(['relevance', 'price_asc', 'price_desc', 'newest', 'bestseller', 'featured', 'name_asc', 'name_desc'])
const forwardedQueryKeys = [
  'category',
  'brand',
  'price_min',
  'price_max',
  'stock_status',
  'availability',
  'availability_status',
  'featured',
  'new_product',
  'bestseller',
] as const

const activeSearch = computed(() => queryString(route.query.search) || queryString(route.query.q))
const hasSearch = computed(() => Boolean(activeSearch.value))
const searchTerm = ref(activeSearch.value)

const sort = computed({
  get: () => {
    const value = queryString(route.query.sort)

    return supportedSorts.has(value) ? value : 'newest'
  },
  set: (value) => updateQuery({ sort: value, page: undefined }),
})

const catalogQuery = computed(() => {
  const query: Record<string, string | number> = {
    per_page: positiveInteger(route.query.per_page, 24),
  }

  const page = positiveInteger(route.query.page, 1)
  if (page > 1) {
    query.page = page
  }

  if (activeSearch.value) {
    query.search = activeSearch.value
  }

  query.sort = sort.value

  for (const key of forwardedQueryKeys) {
    const value = queryString(route.query[key])

    if (value) {
      query[key] = value
    }
  }

  return query
})

const { data: productsResponse, error, pending } = await useAsyncData(
  'catalog-products',
  () => productsApi.list(catalogQuery.value),
  { watch: [catalogQuery] },
)

const products = computed<ProductCard[]>(() => collectionData<ProductCard>(productsResponse.value))

watch(activeSearch, (value) => {
  searchTerm.value = value
})

function updateQuery(next: Record<string, unknown>) {
  const merged = { ...route.query, ...next }
  const query: Record<string, string | string[]> = {}

  for (const [key, value] of Object.entries(merged)) {
    const normalized = routeQueryValue(value)

    if (normalized !== undefined) {
      query[key] = normalized
    }
  }

  router.push({ query })
}

function applySearch() {
  const search = searchTerm.value.trim()

  updateQuery({ search: search || undefined, q: undefined, page: undefined })
}

function clearSearch() {
  searchTerm.value = ''
  updateQuery({ search: undefined, q: undefined, page: undefined })
}

function setPage(page: number) {
  updateQuery({ page: page > 1 ? page : undefined })
}

function queryString(value: unknown): string {
  if (Array.isArray(value)) {
    return queryString(value[0])
  }

  if (value === undefined || value === null) {
    return ''
  }

  return String(value).trim()
}

function positiveInteger(value: unknown, fallback: number): number {
  const parsed = Number.parseInt(queryString(value), 10)

  return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback
}

function routeQueryValue(value: unknown): string | string[] | undefined {
  if (Array.isArray(value)) {
    const values = value.map((item) => queryString(item)).filter(Boolean)

    return values.length ? values : undefined
  }

  const normalized = queryString(value)

  return normalized || undefined
}

seo.page('Каталог', 'Активни продукти в публичния каталог на COMPUTER2U.', '/catalog')
</script>
