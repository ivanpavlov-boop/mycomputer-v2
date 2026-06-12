<template>
  <div class="flex flex-wrap gap-2">
    <BaseButton v-if="quote.status === 'draft'" @click="$emit('submit')">Изпрати</BaseButton>
    <BaseButton v-if="quote.status === 'offered'" @click="$emit('accept')">Приеми офертата</BaseButton>
  </div>
</template>

<script setup lang="ts">
defineProps<{ quote: any }>()
defineEmits<{ submit: [], accept: [] }>()
</script>
