<template>
  <div>
    <Breadcrumbs :items="[{ label: 'PC конфигуратор' }]" />
    <main class="container-page py-8">
      <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 class="text-3xl font-extrabold">PC конфигуратор</h1>
          <p class="mt-2 max-w-2xl text-slate-600">Създайте съвместима настолна конфигурация, проверете рисковете и добавете всички компоненти в количката.</p>
        </div>
        <BaseButton @click="createBuild">Нова конфигурация</BaseButton>
      </div>

      <div class="grid gap-6 lg:grid-cols-[1fr_360px]">
        <section class="surface p-5">
          <h2 class="text-xl font-bold">Моите конфигурации</h2>
          <div class="mt-4 grid gap-3">
            <NuxtLink
              v-for="build in buildsList"
              :key="build.id"
              class="rounded-md border border-slate-200 p-4 hover:border-brand-500"
              :to="`/pc-builder/build/${build.id}`"
            >
              <div class="flex items-center justify-between gap-3">
                <span class="font-semibold">{{ build.name }}</span>
                <span class="font-bold text-brand-700">{{ Number(build.total_price).toFixed(2) }} лв.</span>
              </div>
              <div class="mt-1 text-sm text-slate-500">{{ build.items.length }} компонента</div>
            </NuxtLink>
            <EmptyState v-if="loaded && !buildsList.length" title="Няма конфигурации" text="Създайте първата си PC конфигурация." />
          </div>
        </section>

        <BuildTemplates v-if="metaData" :templates="metaData.templates" @use-template="createFromTemplate" />
      </div>
    </main>
  </div>
</template>

<script setup lang="ts">
import type { PcBuild, PcBuilderMeta } from '~/types/api'

const pcBuilder = usePcBuilder()
const router = useRouter()
const buildsList = ref<PcBuild[]>([])
const metaData = ref<PcBuilderMeta | null>(null)
const loaded = ref(false)
const analytics = useAnalytics()

onMounted(async () => {
  const [metaResponse, buildsResponse] = await Promise.all([pcBuilder.meta(), pcBuilder.builds()])
  metaData.value = metaResponse.data
  buildsList.value = buildsResponse.data
  loaded.value = true
})

async function createBuild() {
  await analytics.builderStart()
  const response = await pcBuilder.create({ name: 'Нова PC конфигурация' })
  await router.push(`/pc-builder/build/${response.data.id}`)
}

async function createFromTemplate(name: string) {
  await analytics.builderStart({ template: name })
  const response = await pcBuilder.create({ name })
  await router.push(`/pc-builder/build/${response.data.id}`)
}

useSeo().page('PC конфигуратор', 'Създайте съвместима настолна PC конфигурация.', '/pc-builder')
</script>
