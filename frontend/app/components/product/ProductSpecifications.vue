<template>
  <section v-if="visibleGroups.length" aria-labelledby="product-specifications-heading">
    <h2 id="product-specifications-heading" class="text-lg font-semibold text-slate-950">
      Характеристики
    </h2>

    <div class="mt-5 space-y-7">
      <section v-for="group in visibleGroups" :key="group.key" :aria-labelledby="`specification-group-${group.key}`">
        <h3 :id="`specification-group-${group.key}`" class="border-b border-slate-200 pb-2 text-base font-semibold text-slate-900">
          {{ group.label }}
        </h3>

        <dl class="divide-y divide-slate-100">
          <div
            v-for="item in group.items"
            :key="item.key"
            class="grid min-w-0 gap-1 py-3 sm:grid-cols-[minmax(0,40%)_minmax(0,1fr)] sm:gap-5"
          >
            <dt class="min-w-0 break-words text-sm text-slate-600">
              {{ item.label }}
            </dt>
            <dd class="min-w-0 break-words text-sm font-medium text-slate-950">
              {{ item.display_value }}
            </dd>
          </div>
        </dl>
      </section>
    </div>
  </section>
</template>

<script setup lang="ts">
import type { ProductSpecificationGroup } from '~/types/api'

const props = defineProps<{ groups: ProductSpecificationGroup[] }>()
const visibleGroups = computed(() => props.groups.filter(group => group.items.length > 0))
</script>
