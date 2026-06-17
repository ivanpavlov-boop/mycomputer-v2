<template>
  <div class="container-page py-8">
    <div class="grid gap-6 lg:grid-cols-[260px_1fr]">
      <AccountSidebar />
      <section class="surface p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h1 class="text-2xl font-bold">PC конфигурации</h1>
          <BaseButton @click="createBuild">Нова конфигурация</BaseButton>
        </div>
        <div class="mt-5 grid gap-3">
          <div v-for="build in buildsList" :key="build.id" class="rounded-md border border-slate-200 p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
              <NuxtLink class="font-semibold hover:text-brand-700" :to="`/pc-builder/build/${build.id}`">{{ build.name }}</NuxtLink>
              <div class="flex items-center gap-3">
                <span class="font-bold text-brand-700">{{ Number(build.total_price).toFixed(2) }} EUR</span>
                <button class="text-sm font-semibold text-red-700" @click="deleteBuild(build.id)">Изтрий</button>
              </div>
            </div>
            <p class="mt-1 text-sm text-slate-500">{{ build.items.length }} компонента</p>
          </div>
          <EmptyState v-if="loaded && !buildsList.length" title="Няма запазени конфигурации" text="Създайте конфигурация от PC Builder." />
        </div>
      </section>
    </div>
  </div>
</template>

<script setup lang="ts">
import type { PcBuild } from '~/types/api'

const auth = useAuthStore()
const pcBuilder = usePcBuilder()
const router = useRouter()
const buildsList = ref<PcBuild[]>([])
const loaded = ref(false)

onMounted(async () => {
  await auth.fetchUser()
  if (!auth.isAuthenticated) {
    await router.push('/login')
    return
  }
  await load()
})

async function load() {
  buildsList.value = (await pcBuilder.builds()).data
  loaded.value = true
}

async function createBuild() {
  const response = await pcBuilder.create({ name: 'Нова PC конфигурация' })
  await router.push(`/pc-builder/build/${response.data.id}`)
}

async function deleteBuild(id: number) {
  await pcBuilder.remove(id)
  await load()
}

useSeo().page('Моите PC конфигурации', 'Запазени PC конфигурации в профила.', '/account/pc-builds')
</script>
