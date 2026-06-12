<template>
  <aside class="space-y-5">
    <div class="surface p-4">
      <p class="font-semibold">Цена</p>
      <div class="mt-3 grid grid-cols-2 gap-2">
        <BaseInput :model-value="model.price_min" placeholder="От" @update:model-value="update('price_min', $event)" />
        <BaseInput :model-value="model.price_max" placeholder="До" @update:model-value="update('price_max', $event)" />
      </div>
    </div>
    <div v-if="filters?.brands?.length" class="surface p-4">
      <p class="font-semibold">Марка</p>
      <label v-for="brand in filters.brands" :key="brand.id" class="mt-3 flex items-center gap-2 text-sm">
        <input type="radio" name="brand" :checked="model.brand === brand.slug" @change="update('brand', brand.slug)">
        <span>{{ brand.name }} ({{ brand.products_count }})</span>
      </label>
    </div>
    <div v-if="filters?.stock_statuses" class="surface p-4">
      <p class="font-semibold">Наличност</p>
      <label v-for="(count, status) in filters.stock_statuses" :key="status" class="mt-3 flex items-center gap-2 text-sm">
        <input type="radio" name="stock" :checked="model.stock_status === status" @change="update('stock_status', status)">
        <span>{{ statusLabel(String(status)) }} ({{ count }})</span>
      </label>
    </div>
    <div v-for="attribute in filters?.attributes || []" :key="attribute.id" class="surface p-4">
      <p class="font-semibold">{{ attribute.name }}</p>
      <label v-for="value in attribute.values" :key="value.id" class="mt-3 flex items-center gap-2 text-sm">
        <input type="checkbox" :checked="selectedAttributes.includes(value.slug)" @change="toggleAttribute(value.slug)">
        <span>{{ value.value }} ({{ value.products_count }})</span>
      </label>
    </div>
    <BaseButton variant="secondary" class="w-full" @click="$emit('reset')">Изчисти филтрите</BaseButton>
  </aside>
</template>

<script setup lang="ts">
import type { CategoryFilters } from '~/types/api'

const props = defineProps<{ filters?: CategoryFilters; model: Record<string, any> }>()
const emit = defineEmits<{ update: [key: string, value: unknown]; reset: [] }>()

const selectedAttributes = computed<string[]>(() => Array.isArray(props.model.attributes) ? props.model.attributes : [])
const update = (key: string, value: unknown) => emit('update', key, value)
function toggleAttribute(slug: string) {
  const next = selectedAttributes.value.includes(slug)
    ? selectedAttributes.value.filter((item) => item !== slug)
    : [...selectedAttributes.value, slug]
  update('attributes', next)
}
const statusLabel = (status: string) => ({ in_stock: 'В наличност', limited: 'Ограничена', out_of_stock: 'Изчерпан', preorder: 'Предварителна' }[status] || status)
</script>
