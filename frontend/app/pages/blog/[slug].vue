<template>
  <div v-if="post">
    <Breadcrumbs :items="[{ label: 'Блог', to: '/blog' }, { label: post.title }]" />
    <article class="container-page max-w-4xl">
      <p v-if="post.category" class="text-sm font-semibold text-brand-700">{{ post.category.name }}</p>
      <h1 class="mt-2 text-4xl font-bold">{{ post.title }}</h1>
      <p class="mt-3 text-sm text-slate-500">{{ post.reading_time || 1 }} мин. четене</p>
      <BlogPostContent class="mt-8" :content="post.content" />
    </article>
    <section v-if="post.related_products?.length" class="container-page mt-10">
      <h2 class="section-title">Свързани продукти</h2>
      <ProductGrid class="mt-5" :products="post.related_products" />
    </section>
  </div>
</template>

<script setup lang="ts">
const route = useRoute()
const blog = useBlog()
const seo = useSeo()
const slug = String(route.params.slug)
const { data } = await useAsyncData(`blog-${slug}`, () => blog.post(slug))
const post = computed(() => data.value?.data)

watchEffect(() => {
  if (!post.value) return
  useSeoMeta({
    title: String(post.value.seo?.meta_title || post.value.title),
    description: String(post.value.seo?.meta_description || post.value.excerpt || ''),
    ogTitle: String(post.value.seo?.og_title || post.value.title),
    ogDescription: String(post.value.seo?.og_description || post.value.excerpt || ''),
  })
  useHead({
    script: [{ type: 'application/ld+json', children: JSON.stringify(post.value.structured_data || {}) }],
  })
})
</script>
