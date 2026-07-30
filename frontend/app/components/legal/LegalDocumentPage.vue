<template>
  <div>
    <LayoutBreadcrumbs :items="[{ label: document.title }]" />
    <main class="container-page">
      <article class="mx-auto max-w-4xl">
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

        <nav class="legal-toc my-6 rounded-md border border-slate-200 p-4" aria-label="Съдържание">
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
            <ul v-if="section.items?.length" class="mt-3 list-disc space-y-2 pl-6 text-slate-700">
              <li v-for="item in section.items" :key="item">{{ item }}</li>
            </ul>
            <div
              v-if="section.formFields?.length"
              class="mt-5 break-inside-avoid rounded-md border border-slate-300 p-4"
            >
              <h3 class="font-semibold text-slate-950">{{ section.formTitle }}</h3>
              <p v-if="section.formIntro" class="mt-2 text-sm leading-6 text-slate-700">
                {{ section.formIntro }}
              </p>
              <div class="mt-4 space-y-3 text-sm leading-6 text-slate-800">
                <p v-for="field in section.formFields" :key="field">{{ field }}</p>
              </div>
            </div>
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
  items?: readonly string[]
  formTitle?: string
  formIntro?: string
  formFields?: readonly string[]
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
  canonicalPath: string
}>()

useSeo().page(props.document.title, props.document.description, props.canonicalPath)
useHead({
  htmlAttrs: { lang: 'bg' },
  meta: [{ name: 'robots', content: 'index, follow' }],
})
</script>

<style scoped>
@media print {
  .legal-toc {
    display: none;
  }

  article {
    max-width: none;
  }
}
</style>
