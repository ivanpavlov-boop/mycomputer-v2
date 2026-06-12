<template>
  <div>
    <Breadcrumbs :items="[{ label: 'PC конфигуратор', to: '/pc-builder' }, { label: 'Шаблони' }]" />
    <main class="container-page py-8">
      <h1 class="text-3xl font-extrabold">Шаблони за конфигурации</h1>
      <p class="mt-2 max-w-2xl text-slate-600">Изберете отправна точка според бюджет и употреба. След това можете да сменяте компоненти и да проверите съвместимостта.</p>
      <BuildTemplates v-if="metaData" class="mt-6" :templates="metaData.templates" @use-template="createFromTemplate" />
    </main>
  </div>
</template>

<script setup lang="ts">
import type { PcBuilderMeta } from '~/types/api'

const pcBuilder = usePcBuilder()
const router = useRouter()
const metaData = ref<PcBuilderMeta | null>(null)

onMounted(async () => {
  metaData.value = (await pcBuilder.meta()).data
})

async function createFromTemplate(name: string) {
  const response = await pcBuilder.create({ name })
  await router.push(`/pc-builder/build/${response.data.id}`)
}

useSeo().page('Шаблони за PC конфигурации', 'Готови отправни точки за гейминг, офис и workstation компютри.', '/pc-builder/templates')
</script>
