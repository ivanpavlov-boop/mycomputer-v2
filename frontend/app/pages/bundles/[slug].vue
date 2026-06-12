<template>
  <main class="container py-8">
    <LoadingState v-if="pending" />
    <ErrorState v-else-if="error || !bundle" title="Комплектът не е намерен" />
    <div v-else>
      <Breadcrumbs :items="[{ label: 'Комплекти', to: '/bundles' }, { label: bundle.name }]" />

      <section class="mt-6 grid gap-8 lg:grid-cols-[1fr_360px]">
        <div>
          <div class="surface overflow-hidden">
            <div class="flex aspect-[16/9] items-center justify-center bg-slate-100 p-6">
              <NuxtImg
                v-if="bundle.image_path"
                :src="imageSrc(bundle.image_path)"
                :alt="bundle.name"
                class="h-full w-full object-contain"
              />
              <span v-else class="text-6xl text-slate-300">▦</span>
            </div>
            <div class="p-6">
              <h1 class="text-3xl font-bold">{{ bundle.name }}</h1>
              <p v-if="bundle.short_description" class="mt-3 text-lg text-slate-600">{{ bundle.short_description }}</p>
              <div v-if="bundle.items?.length" class="mt-6">
                <h2 class="text-xl font-semibold">Включени продукти</h2>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                  <ProductCard v-for="line in fixedProducts" :key="line.id" :product="line.product!" />
                </div>
              </div>
              <BundleOptions
                class="mt-6"
                :bundle-id="bundle.id"
                :options="bundle.options || []"
                @selected="selectedItems = $event"
              />
              <div v-if="bundle.description" class="prose mt-8 max-w-none" v-html="bundle.description" />
            </div>
          </div>
        </div>

        <BundlePriceBox :bundle="bundle" :selected-items="selectedItems" />
      </section>
    </div>
  </main>
</template>

<script setup lang="ts">
const route = useRoute()
const slug = computed(() => String(route.params.slug))
const selectedItems = ref<Array<Record<string, unknown>>>([])
const { show } = useBundles()
const { data, pending, error } = await useAsyncData(`bundle-${slug.value}`, () => show(slug.value))
const bundle = computed(() => data.value?.data)
const fixedProducts = computed(() => (bundle.value?.items || []).filter((line) => line.product))

const config = useRuntimeConfig()
const storageBase = computed(() => String(config.public.apiBaseUrl).replace(/\/api\/v1\/?$/, ''))
const imageSrc = (path: string) => path.startsWith('http') ? path : `${storageBase.value}/storage/${path}`

useSeoMeta({
  title: () => bundle.value?.seo?.meta_title || bundle.value?.name || 'Комплект',
  description: () => bundle.value?.seo?.meta_description || bundle.value?.short_description || '',
  ogTitle: () => bundle.value?.name || 'Комплект',
  ogDescription: () => bundle.value?.short_description || '',
})
</script>
