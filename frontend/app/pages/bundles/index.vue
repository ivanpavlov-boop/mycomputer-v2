<template>
  <main class="container py-8">
    <div class="mb-6">
      <h1 class="text-3xl font-bold">Комплекти и готови пакети</h1>
      <p class="mt-2 max-w-2xl text-slate-600">
        Подбрани комбинации от продукти с по-добра крайна цена и проверена съвместимост.
      </p>
    </div>

    <LoadingState v-if="pending" />
    <ErrorState v-else-if="error" title="Не успяхме да заредим комплектите" />
    <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <BundleCard v-for="bundle in bundles" :key="bundle.id" :bundle="bundle" />
    </div>
  </main>
</template>

<script setup lang="ts">
const { list } = useBundles()
const { data, pending, error } = await useAsyncData('bundles', () => list())
const bundles = computed(() => data.value?.data || [])

useSeoMeta({
  title: 'Комплекти и готови пакети | mycomputer.bg',
  description: 'Готови продуктови комплекти и конфигурируеми пакети за компютърна периферия, аксесоари и хардуер.',
})
</script>
