<template>
  <div>
    <LayoutBreadcrumbs :items="[{ label: document.title }]" />
    <main class="container-page">
      <article class="mx-auto max-w-4xl">
        <div
          v-if="isDraft"
          class="mb-6 rounded-md border border-amber-300 bg-amber-50 p-4 text-amber-950"
          role="status"
        >
          <p class="font-semibold">Проект за правен преглед</p>
          <p class="mt-1 text-sm">
            Този документ е публикуван в тестова среда за преглед и все още не е одобрен за активиране на онлайн поръчки.
          </p>
        </div>

        <header class="border-b border-slate-200 pb-6">
          <h1 class="text-3xl font-bold text-slate-950">{{ document.title }}</h1>
          <p class="mt-3 text-slate-700">{{ document.description }}</p>
          <dl class="mt-4 grid gap-2 text-sm text-slate-600 sm:grid-cols-2">
            <div>
              <dt class="font-semibold text-slate-800">Версия</dt>
              <dd>{{ version }}</dd>
            </div>
            <div>
              <dt class="font-semibold text-slate-800">В сила от</dt>
              <dd>{{ effectiveDate || 'Не е определена' }}</dd>
            </div>
          </dl>
        </header>

        <nav class="my-6 rounded-md border border-slate-200 p-4" aria-label="Съдържание">
          <p class="font-semibold text-slate-900">Съдържание</p>
          <ol class="mt-3 grid gap-2 text-sm">
            <li v-for="(section, index) in document.sections" :key="section.title">
              <a class="text-brand-700 underline-offset-2 hover:underline" :href="`#section-${index + 1}`">
                {{ section.title }}
              </a>
            </li>
          </ol>
        </nav>

        <div class="space-y-8">
          <section
            v-for="(section, index) in document.sections"
            :id="`section-${index + 1}`"
            :key="section.title"
            class="scroll-mt-6"
          >
            <h2 class="text-xl font-semibold text-slate-950">{{ section.title }}</h2>
            <p
              v-for="paragraph in section.paragraphs"
              :key="paragraph"
              class="mt-3 leading-7 text-slate-700"
            >
              {{ paragraph }}
            </p>
          </section>
        </div>
      </article>
    </main>
  </div>
</template>

<script setup lang="ts">
interface LegalSection {
  title: string
  paragraphs: readonly string[]
}

interface LegalDocument {
  title: string
  description: string
  sections: readonly LegalSection[]
}

const props = defineProps<{
  document: LegalDocument
  version: string
  effectiveDate: string | null
  isDraft: boolean
  canonicalPath: string
}>()

useSeo().page(props.document.title, props.document.description, props.canonicalPath)
useHead({
  meta: props.isDraft
    ? [{ name: 'robots', content: 'noindex, nofollow, noarchive' }]
    : [],
})
</script>
