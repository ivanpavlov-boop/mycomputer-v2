<template>
  <div class="surface p-5">
    <div class="flex gap-2 border-b border-slate-200" role="tablist" aria-label="Информация за продукта">
      <button
        v-for="tab in tabs"
        :id="`product-tab-${tab.key}`"
        :key="tab.key"
        type="button"
        role="tab"
        class="px-3 py-2 text-sm font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
        :class="active === tab.key ? 'border-b-2 border-brand-600 text-brand-700' : 'text-slate-600'"
        :aria-selected="active === tab.key"
        :aria-controls="`product-panel-${tab.key}`"
        @click="active = tab.key"
      >
        {{ tab.label }}
      </button>
    </div>
    <div
      v-if="active === 'description'"
      id="product-panel-description"
      class="prose prose-slate mt-5 max-w-none text-sm"
      role="tabpanel"
      aria-labelledby="product-tab-description"
      tabindex="0"
    >
      <div v-if="active === 'description'" v-html="description || 'Няма добавено подробно описание.'" />
    </div>
    <div
      v-else-if="specificationGroups.length"
      id="product-panel-specifications"
      class="mt-5"
      role="tabpanel"
      aria-labelledby="product-tab-specifications"
      tabindex="0"
    >
      <ProductSpecifications :groups="specificationGroups" />
    </div>
  </div>
</template>

<script setup lang="ts">
import type { ProductSpecificationGroup } from '~/types/api'

type ProductTab = 'description' | 'specifications'

const props = defineProps<{
  description?: string | null
  specificationGroups: ProductSpecificationGroup[]
}>()
const active = ref<ProductTab>('description')
const tabs = computed(() => [
  { key: 'description' as const, label: 'Описание' },
  ...(props.specificationGroups.length
    ? [{ key: 'specifications' as const, label: 'Характеристики' }]
    : []),
])

watch(() => props.specificationGroups.length, (count) => {
  if (count === 0 && active.value === 'specifications') {
    active.value = 'description'
  }
})
</script>
