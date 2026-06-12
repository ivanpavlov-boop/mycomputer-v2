<template>
  <div>
    <Breadcrumbs :items="[{ label: 'Блог', to: '/blog' }, { label: `Таг: ${slug}` }]" />
    <div class="container-page">
      <h1 class="text-3xl font-bold">Таг: {{ slug }}</h1>
      <BlogList class="mt-6" :posts="posts" />
    </div>
  </div>
</template>

<script setup lang="ts">
const route = useRoute()
const blog = useBlog()
const slug = String(route.params.slug)
const { data } = await useAsyncData(`blog-tag-${slug}`, () => blog.tagPosts(slug))
const posts = computed(() => data.value?.data || [])
useSeo().page(`Таг: ${slug}`, 'Статии по избрания таг.', `/blog/tag/${slug}`)
</script>
