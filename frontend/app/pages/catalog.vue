<template>
  <div>
    <Breadcrumbs :items="[{ label: 'Каталог' }]" />

    <main class="container-page">
      <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
          <h1 class="text-3xl font-bold text-slate-950">Каталог</h1>
          <p class="mt-2 max-w-2xl text-sm text-slate-600">
            Разгледайте активните продукти в публичния каталог на COMPUTER2U.
          </p>
        </div>

        <div class="grid gap-3 sm:grid-cols-[minmax(220px,1fr)_220px] lg:w-[520px]">
          <BaseInput
            v-model="searchTerm"
            placeholder="Търсене по име, SKU, EAN или MPN"
            @keyup.enter="applySearch"
          />
          <SortSelect v-model="sort" />
        </div>
      </div>

      <div class="mb-4 flex flex-wrap items-center justify-between gap-3 text-sm text-slate-600">
        <span v-if="productsResponse?.meta">
          Намерени продукти: {{ productsResponse.meta.total }}
        </span>
        <BaseButton v-if="route.query.q" variant="secondary" @click="clearSearch">
          Изчисти търсенето
        </BaseButton>
      </div>

      <LoadingState v-if="pending" />
      <ErrorState
        v-else-if="error"
        title="Не успяхме да заредим каталога"
        text="Моля, опитайте отново след малко."
      />
      <template v-else>
        <ProductGrid v-if="products.length" :products="products" />
        <EmptyState
          v-else
          title="Няма активни продукти за показване."
          text="Променете търсенето или опитайте отново по-късно."
        />
        <Pagination :meta="productsResponse?.meta" @change="setPage" />
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

const searchTerm = ref(String(route.query.q || ''))

const sort = computed({
  get: () => String(route.query.sort || 'newest'),
  set: (value) => updateQuery({ sort: value, page: undefined }),
})

const { data: productsResponse, error, pending } = await useAsyncData(
  `catalog-products-${JSON.stringify(route.query)}`,
  () => productsApi.list({ ...route.query, per_page: route.query.per_page || 24 }),
  { watch: [() => route.query] },
)

const products = computed<ProductCard[]>(() => collectionData<ProductCard>(productsResponse.value))

watch(() => route.query.q, (value) => {
  searchTerm.value = String(value || '')
})

function updateQuery(next: Record<string, unknown>) {
  router.push({ query: { ...route.query, ...next } })
}

function applySearch() {
  updateQuery({ q: searchTerm.value || undefined, page: undefined })
}

function clearSearch() {
  searchTerm.value = ''
  updateQuery({ q: undefined, page: undefined })
}

function setPage(page: number) {
  updateQuery({ page })
}

seo.page('Каталог', 'Активни продукти в публичния каталог на COMPUTER2U.', '/catalog')
</script>
