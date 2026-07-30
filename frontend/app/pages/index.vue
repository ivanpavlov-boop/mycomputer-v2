<template>
  <ContentBlockRenderer v-if="cmsPage" :page="cmsPage" />
  <div v-else>
    <section class="bg-slate-950 text-white">
      <div class="container-page grid gap-8 py-12 lg:grid-cols-[1.2fr_0.8fr] lg:py-16">
        <div>
          <p class="text-sm font-semibold text-amber-300">Компютърна техника за работа, игри и бизнес</p>
          <h1 class="mt-3 text-4xl font-bold tracking-normal sm:text-5xl">{{ hero.title }}</h1>
          <p class="mt-4 max-w-2xl text-slate-300">{{ hero.subtitle }}</p>
          <div class="mt-6 flex flex-wrap gap-3">
            <NuxtLink :to="localePath('/catalog')" class="rounded-md bg-brand-600 px-5 py-3 text-sm font-semibold text-white hover:bg-brand-700">Разгледай продукти</NuxtLink>
            <NuxtLink :to="localePath('/categories')" class="rounded-md bg-white px-5 py-3 text-sm font-semibold text-slate-950 hover:bg-slate-100">Категории</NuxtLink>
          </div>
        </div>
        <div class="grid grid-cols-2 gap-3 text-slate-950">
          <div class="rounded-lg bg-white p-4"><p class="text-sm text-slate-500">Лаптопи</p><p class="mt-2 text-2xl font-bold">Business & Gaming</p></div>
          <div class="rounded-lg bg-white p-4"><p class="text-sm text-slate-500">Компоненти</p><p class="mt-2 text-2xl font-bold">CPU, GPU, RAM</p></div>
          <div class="rounded-lg bg-white p-4"><p class="text-sm text-slate-500">Монитори</p><p class="mt-2 text-2xl font-bold">Office & Gaming</p></div>
          <div class="rounded-lg bg-amber-300 p-4"><p class="text-sm text-amber-900">Промоции</p><p class="mt-2 text-2xl font-bold">Топ оферти</p></div>
        </div>
      </div>
    </section>

    <section class="container-page mt-10">
      <h2 class="section-title">Препоръчани категории</h2>
      <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <CatalogCategoryCard v-for="category in home.featured_categories" :key="category.id" :category="category" />
      </div>
    </section>

    <CatalogHomeProductSection title="Препоръчани продукти" :products="home.featured_products" />
    <CatalogHomeProductSection title="Нови продукти" :products="home.new_products" />
    <CatalogHomeProductSection title="Най-продавани" :products="home.bestsellers" />
    <CatalogHomeProductSection title="Промоционални продукти" :products="home.promotional_products" />
  </div>
</template>

<script setup lang="ts">
import type { HomeResponse } from '~/types/api'

const api = useApi()
const content = useContent()
const seo = useSeo()
const localePath = useLocalePath()
const [{ data: cmsData }, { data }] = await Promise.all([
  useAsyncData('content-homepage', () => content.homepage().catch(() => ({ data: null }))),
  useAsyncData('home', () => api.get<{ data: HomeResponse }>('/home')),
])
const cmsPage = computed(() => cmsData.value?.data || null)
const home = computed(() => data.value?.data || {
  hero_banners: [],
  featured_categories: [],
  featured_products: [],
  new_products: [],
  bestsellers: [],
  promotional_products: [],
  latest_articles: [],
})
const hero = computed(() => home.value.hero_banners[0] || { title: 'mycomputer.bg', subtitle: 'Компютърна техника и аксесоари.' })
seo.page('mycomputer.bg', 'Модерен онлайн магазин за компютърна техника.', '/')
</script>
