<template>
  <div class="grid gap-4 sm:grid-cols-2">
    <BaseInput :model-value="city" placeholder="Град" @update:model-value="$emit('update:city', $event)" />
    <BaseInput :model-value="postcode" placeholder="Пощенски код" @update:model-value="$emit('update:postcode', $event)" />
    <textarea
      :value="address"
      class="sm:col-span-2 w-full rounded-md border border-slate-300 p-3 text-sm"
      rows="3"
      placeholder="Адрес за доставка"
      @input="$emit('update:address', ($event.target as HTMLTextAreaElement).value)"
    />
  </div>
</template>

<script setup lang="ts">
defineProps<{ city: string; postcode?: string; address: string }>()
defineEmits<{ 'update:city': [value: string]; 'update:postcode': [value: string]; 'update:address': [value: string] }>()
</script>
