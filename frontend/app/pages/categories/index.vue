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
      <div v-else-if="categories.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        <CategoryCard v-for="category in categories" :key="category.id" :category="category" />
      </div>
      <EmptyState
        v-else
        title="Няма активни категории"
        text="Категориите ще се покажат тук, когато са активни и публични."
      />
    </main>
  </div>
</template>

<script setup lang="ts">
const categoryApi = useCategories()
const seo = useSeo()

const { data: categoryResponse, pending } = await useAsyncData(
  'public-categories',
  () => categoryApi.list({ active: true }),
)

const categories = computed(() => categoryResponse.value?.data || [])

seo.page('Категории', 'Публични продуктови категории в COMPUTER2U.', '/categories')
</script>
