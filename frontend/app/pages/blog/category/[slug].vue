<template>
  <div>
    <Breadcrumbs :items="[{ label: 'Блог', to: '/blog' }, { label: category?.name || 'Категория' }]" />
    <div class="container-page">
      <h1 class="text-3xl font-bold">{{ category?.name || 'Категория' }}</h1>
      <p v-if="category?.description" class="mt-3 text-slate-600">{{ category.description }}</p>
      <BlogList class="mt-6" :posts="posts" />
    </div>
  </div>
</template>

<script setup lang="ts">
const route = useRoute()
const blog = useBlog()
const slug = String(route.params.slug)
const [{ data: categoryData }, { data: postsData }] = await Promise.all([
  useAsyncData(`blog-category-${slug}`, () => blog.category(slug)),
  useAsyncData(`blog-category-posts-${slug}`, () => blog.categoryPosts(slug)),
])
const category = computed(() => categoryData.value?.data)
const posts = computed(() => postsData.value?.data || [])
useSeo().page(category.value?.meta_title || category.value?.name || 'Категория', category.value?.meta_description || '', `/blog/category/${slug}`)
</script>
