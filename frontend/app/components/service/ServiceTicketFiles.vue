<template>
  <div class="surface p-4">
    <h2 class="text-lg font-bold">Файлове</h2>
    <ul class="mt-4 space-y-2 text-sm text-slate-700">
      <li v-for="file in files" :key="file.id" class="flex justify-between rounded-md bg-slate-50 p-2">
        <span>{{ file.file_type }}</span>
        <span>{{ Math.round(file.file_size / 1024) }} KB</span>
      </li>
    </ul>
    <form class="mt-4 flex flex-wrap gap-2" @submit.prevent="submit">
      <input type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" @change="selectFile">
      <BaseButton type="submit">Качи</BaseButton>
    </form>
  </div>
</template>

<script setup lang="ts">
import type { ServiceTicketFile } from '~/types/api'

defineProps<{ files: ServiceTicketFile[] }>()
const emit = defineEmits<{ upload: [file: File] }>()
const selected = ref<File | null>(null)

function selectFile(event: Event) {
  selected.value = (event.target as HTMLInputElement).files?.[0] || null
}

function submit() {
  if (selected.value) emit('upload', selected.value)
}
</script>
