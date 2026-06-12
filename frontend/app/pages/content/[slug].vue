<template>
  <div v-if="page">
    <Breadcrumbs :items="[{ label: 'Страници' }, { label: page.title }]" />
    <ContentBlockRenderer :page="page" />
  </div>
</template>

<script setup lang="ts">
const route = useRoute()
const content = useContent()
const slug = String(route.params.slug)

const { data, error } = await useAsyncData(`content-page-${slug}`, () => content.page(slug))

if (error.value) {
  throw createError({ statusCode: 404, statusMessage: 'Страницата не е намерена' })
}

const page = computed(() => data.value?.data || null)

watchEffect(() => {
  if (!page.value) return

  const seo = page.value.seo || {}

  useSeoMeta({
    title: String(seo.meta_title || page.value.title),
    description: String(seo.meta_description || ''),
    ogTitle: String(seo.og_title || seo.meta_title || page.value.title),
    ogDescription: String(seo.og_description || seo.meta_description || ''),
    ogImage: seo.og_image ? String(seo.og_image) : undefined,
  })

  const schema = page.value.schema

  if (schema) {
    useHead({
      link: seo.canonical_url ? [{ rel: 'canonical', href: String(seo.canonical_url) }] : [],
      script: [{ type: 'application/ld+json', children: JSON.stringify(schema) }],
    })
  } else if (seo.canonical_url) {
    useHead({ link: [{ rel: 'canonical', href: String(seo.canonical_url) }] })
  }
})
</script>
