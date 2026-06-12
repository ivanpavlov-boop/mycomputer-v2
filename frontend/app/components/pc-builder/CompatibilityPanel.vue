<template>
  <section class="surface p-5">
    <div class="flex items-center justify-between gap-3">
      <h2 class="text-lg font-bold">Съвместимост</h2>
      <span
        class="rounded-full px-3 py-1 text-xs font-bold"
        :class="compatibility.compatible ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800'"
      >
        {{ compatibility.compatible ? 'Съвместима' : 'Има проблеми' }}
      </span>
    </div>
    <BuildWarnings class="mt-4" :errors="compatibility.errors" :warnings="compatibility.warnings" />
    <div v-if="compatibility.recommendations.length" class="mt-4 grid gap-2">
      <div v-for="item in compatibility.recommendations" :key="item" class="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-700">
        {{ item }}
      </div>
    </div>
    <p v-if="!compatibility.errors.length && !compatibility.warnings.length && !compatibility.recommendations.length" class="mt-3 text-sm text-slate-500">
      Добавете основни компоненти, за да стартира проверката.
    </p>
  </section>
</template>

<script setup lang="ts">
import type { PcCompatibility } from '~/types/api'

defineProps<{ compatibility: PcCompatibility }>()
</script>
