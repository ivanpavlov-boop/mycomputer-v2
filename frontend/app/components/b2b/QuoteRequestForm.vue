<template>
  <form class="grid gap-4" @submit.prevent="submit">
    <BaseInput v-model="form.notes" label="Бележки към заявката" />
    <div class="grid gap-3">
      <QuoteRequestItems v-model="form.items" />
    </div>
    <BaseButton type="submit">Създай заявка</BaseButton>
  </form>
</template>

<script setup lang="ts">
const emit = defineEmits<{ created: [quote: any] }>()
const b2b = useB2B()
const form = reactive({
  notes: '',
  items: [{ product_id: '', quantity: 1, requested_price: null, notes: '' }],
})

async function submit() {
  const response = await b2b.createQuote({ ...form })
  emit('created', (response as any).data)
}
</script>
