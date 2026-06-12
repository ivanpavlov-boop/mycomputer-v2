<template>
  <div>
    <Breadcrumbs :items="[{ label: 'Блог' }]" />
    <div class="container-page grid gap-8 lg:grid-cols-[1fr_280px]">
      <section>
        <h1 class="text-3xl font-bold">Блог и съвети</h1>
        <BlogList class="mt-6" :posts="posts" />
      </section>
      <BlogSidebar :categories="categories" :tags="tags" />
    </div>
  </div>
</template>

<script setup lang="ts">
const blog = useBlog()
const seo = useSeo()
const [{ data: postData }, { data: categoryData }, { data: tagData }] = await Promise.all([
  useAsyncData('blog-posts', () => blog.posts()),
  useAsyncData('blog-categories', () => blog.categories()),
  useAsyncData('blog-tags', () => blog.tags()),
])
const posts = computed(() => postData.value?.data || [])
const categories = computed(() => categoryData.value?.data || [])
const tags = computed(() => tagData.value?.data || [])
seo.page('Блог', 'Съвети, новини и ръководства за компютърна техника.', '/blog')
</script>
