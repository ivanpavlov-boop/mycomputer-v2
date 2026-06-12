<template>
  <div class="surface p-5">
    <div class="flex gap-2 border-b border-slate-200">
      <button v-for="tab in tabs" :key="tab.key" class="px-3 py-2 text-sm font-semibold" :class="active === tab.key ? 'border-b-2 border-brand-600 text-brand-700' : 'text-slate-600'" @click="active = tab.key">
        {{ tab.label }}
      </button>
    </div>
    <div class="prose prose-slate mt-5 max-w-none text-sm">
      <div v-if="active === 'description'" v-html="description || 'Няма добавено подробно описание.'" />
      <ProductAttributes v-else :groups="attributes" />
    </div>
  </div>
</template>

<script setup lang="ts">
import type { ProductDetail } from '~/types/api'

defineProps<{ description?: string | null; attributes: ProductDetail['attributes'] }>()
const active = ref<'description' | 'attributes'>('description')
const tabs = [
  { key: 'description' as const, label: 'Описание' },
  { key: 'attributes' as const, label: 'Характеристики' },
]
</script>
