<template>
  <form class="surface grid gap-3 p-4" @submit.prevent="submit">
    <label class="grid gap-1 text-sm font-semibold">
      Причина
      <BaseInput v-model="reason" required />
    </label>
    <textarea v-model="message" class="rounded-md border border-slate-300 p-3" rows="3" placeholder="Допълнителна информация" />
    <BaseButton type="submit">Изпрати сигнал</BaseButton>
  </form>
</template>

<script setup lang="ts">
const emit = defineEmits<{ submit: [{ reason: string, message?: string }] }>()
const reason = ref('')
const message = ref('')

function submit() {
  if (!reason.value.trim()) return
  emit('submit', { reason: reason.value.trim(), message: message.value.trim() || undefined })
}
</script>
