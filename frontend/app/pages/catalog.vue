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
        <span v-if="catalogMeta?.total !== undefined">
          Намерени продукти: {{ catalogMeta.total }}
        </span>
        <UiBaseButton v-if="hasSearch" variant="secondary" @click="clearSearch">
          Изчисти търсенето
        </UiBaseButton>
      </div>

      <UiLoadingState v-if="pending" />
      <div v-else-if="error">
        <UiErrorState
          title="Не успяхме да заредим каталога"
          text="Моля, опитайте отново след малко."
        />
        <UiBaseButton v-if="hasAttributeRouteFilters" variant="secondary" class="mt-4" @click="clearAllAttributeFilters">
          Изчисти филтрите по характеристики
        </UiBaseButton>
        <UiBaseButton v-if="hasPriceRouteFilters" variant="secondary" class="mt-4 ml-2" @click="clearPriceFilter">
          Изчисти ценовия филтър
        </UiBaseButton>
      </div>
      <template v-else>
        <div :class="attributeFilters.length || priceFilter ? 'lg:grid lg:grid-cols-[280px_minmax(0,1fr)] lg:gap-6' : ''">
          <CatalogAttributeFilterPanel
            :filters="attributeFilters"
            :selection="attributeSelections"
            :active-count="activeFilterCount"
            :attribute-active-count="activeAttributeFilterCount"
            :price-filter="priceFilter"
            :price-selection="priceSelection"
            @change="setAttributeFilter"
            @clear-attributes="clearAllAttributeFilters"
            @price-change="setPriceFilter"
            @clear-price="clearPriceFilter"
          />
          <section class="min-w-0">
            <CatalogActivePriceFilter
              :filter="priceFilter"
              :selection="priceSelection"
              @clear="clearPriceFilter"
            />
            <CatalogActiveAttributeFilters
              :filters="activeAttributeFilters"
              @remove="removeActiveAttributeFilter"
              @clear-all="clearAllAttributeFilters"
            />
            <CatalogProductGrid v-if="products.length" :products="products" />
            <div v-else class="space-y-4">
              <UiEmptyState
                title="Няма активни продукти за показване."
                text="Променете търсенето или премахнете част от филтрите."
              />
              <UiBaseButton v-if="hasAttributeRouteFilters" variant="secondary" @click="clearAllAttributeFilters">
                Изчисти филтрите по характеристики
              </UiBaseButton>
              <UiBaseButton v-if="hasPriceRouteFilters" variant="secondary" @click="clearPriceFilter">
                Изчисти ценовия филтър
              </UiBaseButton>
            </div>
            <CatalogPagination :meta="catalogMeta" @change="setPage" />
          </section>
        </div>
      </template>
    </main>
  </div>
</template>

<script setup lang="ts">
import type { ProductCard } from '~/types/api'
import type { AttributeFilterSelection } from '~/utils/attributeFilters'
import type { PriceFilterSelection } from '~/utils/priceFilters'
import { paginatedResource } from '~/utils/apiCollections'
import {
  attributeFilterApiQuery,
  clearAttributeFilters,
  hasAttributeFilters,
  parseAttributeFilterQuery,
  replaceAttributeFilter,
} from '~/utils/attributeFilters'
import {
  clearPriceFilters,
  hasPriceFilters,
  parsePriceFilterQuery,
  replacePriceFilters,
} from '~/utils/priceFilters'
import { normalizeCatalogSort } from '~/utils/catalogSorts'
import { positiveInteger, queryString, routeQueryValue } from '~/utils/routeQuery'

const route = useRoute()
const router = useRouter()
const productsApi = useProducts()
const seo = useSeo()
const { locale } = useI18n()

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
const attributeSelections = computed(() => parseAttributeFilterQuery(route.query))
const hasAttributeRouteFilters = computed(() => hasAttributeFilters(route.query))
const priceSelection = computed(() => parsePriceFilterQuery(route.query))
const hasPriceRouteFilters = computed(() => hasPriceFilters(route.query))

const sort = computed({
  get: () => normalizeCatalogSort(route.query.sort),
  set: (value) => updateQuery({ sort: normalizeCatalogSort(value), page: undefined }),
})

const catalogQuery = computed(() => {
  const query: Record<string, string | number | string[]> = {
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

  Object.assign(query, attributeFilterApiQuery(route.query))

  return query
})

const { data: productsResponse, error, pending } = await useAsyncData(
  () => `catalog-products-${locale.value}`,
  async () => paginatedResource<ProductCard>(await productsApi.list(catalogQuery.value)),
  {
    watch: [catalogQuery, locale],
    default: () => paginatedResource<ProductCard>(null),
  },
)

const products = computed<ProductCard[]>(() => productsResponse.value?.data ?? [])
const catalogMeta = computed(() => productsResponse.value?.meta)
const attributeFilters = computed(() => productsResponse.value?.filters ?? [])
const activeAttributeFilters = computed(() => productsResponse.value?.active_filters ?? [])
const priceFilter = computed(() => productsResponse.value?.price_filter ?? null)
const activeAttributeFilterCount = computed(() => activeAttributeFilters.value.reduce(
  (count, filter) => count + (filter.values?.length || 1),
  0,
))
const activeFilterCount = computed(() => activeAttributeFilterCount.value + (hasPriceRouteFilters.value ? 1 : 0))

watch(activeSearch, (value) => {
  searchTerm.value = value
})

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

function setAttributeFilter(key: string, selection: AttributeFilterSelection) {
  router.push({ query: replaceAttributeFilter(route.query, key, selection) })
}

function removeActiveAttributeFilter(key: string, value?: string) {
  const selected = attributeSelections.value[key]

  if (value && selected?.values) {
    setAttributeFilter(key, { values: selected.values.filter((item) => item !== value) })

    return
  }

  setAttributeFilter(key, {})
}

function clearAllAttributeFilters() {
  router.push({ query: clearAttributeFilters(route.query) })
}

function setPriceFilter(selection: PriceFilterSelection) {
  router.push({ query: replacePriceFilters(route.query, selection) })
}

function clearPriceFilter() {
  router.push({ query: clearPriceFilters(route.query) })
}

seo.page('Каталог', 'Активни продукти в публичния каталог на COMPUTER2U.', '/catalog')
</script>
