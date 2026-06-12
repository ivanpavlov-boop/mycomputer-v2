<template>
  <div v-if="groups.length" class="space-y-5">
    <section v-for="group in groups" :key="group.name" class="rounded-md border border-slate-200 p-4">
      <h3 class="font-semibold">{{ label(group.name) }}</h3>
      <div class="mt-3 grid gap-3 sm:grid-cols-2">
        <label
          v-for="option in group.options"
          :key="option.id"
          class="flex cursor-pointer gap-3 rounded-md border p-3"
          :class="selected[group.name] === option.product?.id ? 'border-brand-600 bg-brand-50' : 'border-slate-200 bg-white'"
        >
          <input
            v-model="selected[group.name]"
            type="radio"
            class="mt-1"
            :name="`bundle-${bundleId}-${group.name}`"
            :value="option.product?.id"
            @change="emitSelection"
          >
          <div>
            <div class="font-medium">{{ option.product?.name }}</div>
            <div class="text-sm text-slate-600">
              {{ adjustment(option.price_adjustment) }}
            </div>
          </div>
        </label>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
import type { ProductBundleOption } from '~/types/api'

const props = defineProps<{ bundleId: number; options: ProductBundleOption[] }>()
const emit = defineEmits<{ selected: [Array<{ component_group: string; product_id: number }>] }>()

const selected = reactive<Record<string, number>>({})
const groups = computed(() => {
  const map = new Map<string, ProductBundleOption[]>()
  for (const option of props.options || []) {
    if (!map.has(option.component_group)) map.set(option.component_group, [])
    map.get(option.component_group)?.push(option)
  }
  return Array.from(map.entries()).map(([name, options]) => ({ name, options }))
})

function emitSelection() {
  emit('selected', Object.entries(selected).map(([component_group, product_id]) => ({ component_group, product_id })))
}

function label(value: string) {
  return value.replaceAll('_', ' ')
}

function adjustment(value?: string | number | null) {
  const amount = Number(value || 0)
  if (amount === 0) return 'Включено в комплекта'
  return amount > 0 ? `+${amount.toFixed(2)} лв.` : `${amount.toFixed(2)} лв.`
}

watch(
  () => props.options,
  (options) => {
    for (const option of options || []) {
      if (option.is_default && option.product?.id && !selected[option.component_group]) {
        selected[option.component_group] = option.product.id
      }
    }
    emitSelection()
  },
  { immediate: true },
)
</script>
