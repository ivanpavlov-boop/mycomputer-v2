<template>
  <div v-if="page">
    <Breadcrumbs :items="[{ label: 'Страници' }, { label: page.title }]" />
    <section class="container-page max-w-4xl">
      <h1 class="text-4xl font-bold">{{ page.title }}</h1>
      <SeoPageContent
        class="mt-8"
        :content="page.content"
        :related-products="page.related_products || []"
        :related-categories="page.related_categories || []"
      />
    </section>
    <section v-if="page.related_products?.length" class="container-page mt-10">
      <h2 class="section-title">Подходящи продукти</h2>
      <ProductGrid class="mt-5" :products="page.related_products" />
    </section>
  </div>
</template>

<script setup lang="ts">
const route = useRoute()
const blog = useBlog()
const slug = String(route.params.slug)
const { data } = await useAsyncData(`static-page-${slug}`, () => blog.seoPage(slug))
const page = computed(() => data.value?.data)

watchEffect(() => {
  if (!page.value) return
  useSeoMeta({
    title: String(page.value.seo?.meta_title || page.value.title),
    description: String(page.value.seo?.meta_description || ''),
    ogTitle: String(page.value.seo?.og_title || page.value.title),
    ogDescription: String(page.value.seo?.og_description || ''),
  })
})
</script>
