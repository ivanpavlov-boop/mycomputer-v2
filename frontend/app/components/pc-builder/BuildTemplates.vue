<template>
  <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
    <article v-for="template in templates" :key="template.name" class="surface p-5">
      <h3 class="text-lg font-bold">{{ template.name }}</h3>
      <div class="mt-1 text-sm font-semibold text-brand-700">{{ template.budget }}</div>
      <div class="mt-3 flex flex-wrap gap-2">
        <span v-for="focus in template.focus" :key="focus" class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
          {{ focus }}
        </span>
      </div>
      <BaseButton class="mt-4" @click="$emit('use-template', template.name)">Използвай шаблон</BaseButton>
    </article>
  </section>
</template>

<script setup lang="ts">
import type { PcBuilderPreset } from '~/types/api'

defineProps<{ templates: PcBuilderPreset[] }>()
defineEmits<{ 'use-template': [name: string] }>()
</script>
