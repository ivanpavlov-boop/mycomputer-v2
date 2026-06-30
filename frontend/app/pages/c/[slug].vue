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
          <span v-if="productsResponse?.meta">
            Продукти: {{ productsResponse.meta.total }}
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

        <Pagination :meta="productsResponse?.meta" @change="setPage" />
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
import { collectionData } from '~/utils/apiCollections'

const route = useRoute()
const router = useRouter()
const categories = useCategories()
const seo = useSeo()
const slug = computed(() => String(route.params.slug))
const sort = computed({
  get: () => String(route.query.sort || 'newest'),
  set: (value) => updateQuery({ sort: value, page: undefined }),
})

const { data: categoryData, error: categoryError, pending: categoryPending } = await useAsyncData(
  `category-${slug.value}`,
  () => categories.detail(slug.value),
  { watch: [() => route.params.slug] },
)

const { data: productsResponse, error: productsError, pending: productsPending } = await useAsyncData(
  `category-products-${slug.value}-${JSON.stringify(route.query)}`,
  () => categories.products(slug.value, { ...route.query, per_page: route.query.per_page || 24 }),
  { watch: [() => route.params.slug, () => route.query] },
)

const category = computed(() => categoryData.value?.data)
const products = computed<ProductCard[]>(() => collectionData<ProductCard>(productsResponse.value))

function updateQuery(next: Record<string, unknown>) {
  router.push({ query: { ...route.query, ...next } })
}

function setPage(page: number) {
  updateQuery({ page })
}

watchEffect(() => {
  if (category.value) {
    seo.page(category.value.meta_title || category.value.name, category.value.meta_description || '', `/c/${category.value.slug}`)
  }
})
</script>
