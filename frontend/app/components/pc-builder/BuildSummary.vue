<template>
  <section class="surface p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-extrabold">{{ build.name }}</h1>
        <p v-if="build.description" class="mt-1 text-sm text-slate-500">{{ build.description }}</p>
      </div>
      <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-700">{{ statusLabel }}</span>
    </div>

    <div class="mt-5 grid gap-3">
      <div v-for="type in componentTypes" :key="type" class="rounded-md border border-slate-200 p-3">
        <div class="mb-2 text-xs font-bold uppercase text-slate-500">{{ labels[type] }}</div>
        <div v-if="itemByType(type)" class="flex items-center justify-between gap-3">
          <NuxtLink class="font-semibold hover:text-brand-700" :to="`/p/${itemByType(type)?.product.slug}`">
            {{ itemByType(type)?.product.name }}
          </NuxtLink>
          <button class="text-sm font-semibold text-red-700" @click="$emit('remove-item', itemByType(type)!.id)">Премахни</button>
        </div>
        <div v-else class="text-sm text-slate-400">Не е избран компонент</div>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import type { PcBuild, PcComponentType } from '~/types/api'

const props = defineProps<{ build: PcBuild; componentTypes: PcComponentType[] }>()
defineEmits<{ 'remove-item': [itemId: number] }>()

const labels: Record<string, string> = {
  cpu: 'Процесор',
  motherboard: 'Дънна платка',
  ram: 'Памет',
  gpu: 'Видео карта',
  psu: 'Захранване',
  case: 'Кутия',
  storage: 'Диск',
  cooler: 'Охлаждане',
  operating_system: 'Операционна система',
  monitor: 'Монитор',
  keyboard: 'Клавиатура',
  mouse: 'Мишка',
  speakers: 'Тонколони',
  accessories: 'Аксесоари',
}

const statusLabel = computed(() => ({
  draft: 'Чернова',
  saved: 'Запазена',
  shared: 'Споделена',
  ordered: 'Поръчана',
}[props.build.status] || props.build.status))

function itemByType(type: PcComponentType) {
  return props.build.items.find((item) => item.component_type === type)
}
</script>
