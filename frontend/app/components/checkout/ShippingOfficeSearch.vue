<template>
  <div class="space-y-3">
    <BaseInput :model-value="city" placeholder="Град" @update:model-value="$emit('update:city', $event)" />
    <BaseInput v-model="search" placeholder="Търсене по офис или адрес" />
    <div class="max-h-64 overflow-auto rounded-md border border-slate-200 bg-white">
      <button
        v-for="office in offices"
        :key="office.id"
        type="button"
        class="block w-full border-b border-slate-100 p-3 text-left text-sm hover:bg-slate-50"
        @click="$emit('select', office)"
      >
        <span class="font-semibold">{{ office.name }}</span>
        <span class="mt-1 block text-slate-600">{{ office.city }}, {{ office.address }}</span>
      </button>
      <p v-if="!offices.length" class="p-3 text-sm text-slate-500">Няма намерени офиси.</p>
    </div>
  </div>
</template>

<script setup lang="ts">
import type { ShippingOffice } from '~/types/api'

defineProps<{ city: string; offices: ShippingOffice[] }>()
defineEmits<{ 'update:city': [value: string]; select: [office: ShippingOffice] }>()
const search = defineModel<string>('search', { default: '' })
</script>
