<template>
  <BaseButton variant="secondary" @click="$emit('request')">Заяви B2B оферта</BaseButton>
</template>

<script setup lang="ts">
defineEmits<{ request: [] }>()
</script>
