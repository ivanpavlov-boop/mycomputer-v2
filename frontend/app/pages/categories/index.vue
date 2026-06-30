<template>
  <div>
    <Breadcrumbs :items="[{ label: 'Категории' }]" />

    <main class="container-page">
      <div class="mb-6">
        <h1 class="text-3xl font-bold text-slate-950">Категории</h1>
        <p class="mt-2 max-w-2xl text-sm text-slate-600">
          Изберете категория, за да разгледате активните продукти в нея.
        </p>
      </div>

      <LoadingState v-if="pending" />
      <ErrorState
        v-else-if="error"
        title="Категориите не могат да се заредят"
        text="Моля, опитайте отново след малко."
      />
      <div v-else-if="categories.length" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <article
          v-for="category in categories"
          :key="category.id"
          class="surface flex h-full flex-col overflow-hidden border border-slate-200 bg-white"
        >
          <NuxtLink :to="`/c/${category.slug}`" class="block p-4">
            <div class="flex aspect-[16/9] items-center justify-center overflow-hidden rounded-md bg-slate-100 text-brand-700">
              <NuxtImg
                v-if="category.image"
                :src="categoryImageSrc(category.image)"
                :alt="category.name"
                class="h-full w-full object-cover"
                loading="lazy"
              />
              <span v-else class="text-4xl font-semibold" aria-hidden="true">{{ category.icon || '□' }}</span>
            </div>
          </NuxtLink>

          <div class="flex flex-1 flex-col gap-4 p-4 pt-0">
            <div>
              <NuxtLink :to="`/c/${category.slug}`" class="text-lg font-semibold text-slate-950 hover:text-brand-700">
                {{ category.name }}
              </NuxtLink>
              <p v-if="category.description" class="mt-2 line-clamp-2 text-sm text-slate-600">
                {{ category.description }}
              </p>
            </div>

            <div v-if="childCategories(category).length" class="rounded-md bg-slate-50 p-3">
              <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Подкатегории</p>
              <div class="mt-2 flex flex-wrap gap-2">
                <NuxtLink
                  v-for="child in childCategories(category)"
                  :key="child.id"
                  :to="`/c/${child.slug}`"
                  class="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-medium text-slate-700 hover:border-brand-200 hover:text-brand-700"
                >
                  {{ child.name }}
                </NuxtLink>
              </div>
            </div>

            <div class="mt-auto flex items-center justify-between gap-3 pt-2">
              <span class="text-xs text-slate-500">Продукти в категорията</span>
              <NuxtLink
                :to="`/c/${category.slug}`"
                class="inline-flex items-center justify-center rounded-md border border-brand-200 bg-brand-50 px-3 py-2 text-sm font-semibold text-brand-700 hover:bg-brand-100"
              >
                Виж категорията
              </NuxtLink>
            </div>
          </div>
        </article>
      </div>
      <EmptyState
        v-else
        title="Няма активни категории за показване"
        text="Категориите ще се покажат тук, когато са активни и публични."
      />
    </main>
  </div>
</template>

<script setup lang="ts">
import type { Category } from '~/types/api'
import { collectionData } from '~/utils/apiCollections'

const categoryApi = useCategories()
const seo = useSeo()
const config = useRuntimeConfig()

const { data: categoryResponse, pending, error } = await useAsyncData(
  'public-category-navigation',
  () => categoryApi.navigation(),
)

const categories = computed<Category[]>(() => collectionData<Category>(categoryResponse.value))
const storageBase = computed(() => String(config.public.apiBaseUrl).replace(/\/api\/v1\/?$/, ''))

function childCategories(category: Category): Category[] {
  return category.children || []
}

function categoryImageSrc(path: string): string {
  return path.startsWith('http') ? path : `${storageBase.value}/storage/${path}`
}

seo.page('Категории', 'Публични продуктови категории в COMPUTER2U.', '/categories')
</script>
