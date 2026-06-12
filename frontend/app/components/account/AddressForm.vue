<template>
  <form class="grid gap-3 md:grid-cols-2" @submit.prevent="submit">
    <BaseSelect v-model="form.type">
      <option value="shipping">Доставка</option>
      <option value="billing">Фактуриране</option>
    </BaseSelect>
    <label class="flex items-center gap-2 text-sm"><input v-model="form.is_default" type="checkbox"> Основен адрес</label>
    <BaseInput v-model="form.first_name" placeholder="Име" />
    <BaseInput v-model="form.last_name" placeholder="Фамилия" />
    <BaseInput v-model="form.phone" placeholder="Телефон" />
    <BaseInput v-model="form.country" placeholder="Държава" />
    <BaseInput v-model="form.city" placeholder="Град" />
    <BaseInput v-model="form.postcode" placeholder="Пощенски код" />
    <BaseInput v-model="form.address_line_1" placeholder="Адрес" />
    <BaseInput v-model="form.address_line_2" placeholder="Адрес 2" />
    <BaseInput v-model="form.company_name" placeholder="Фирма" />
    <BaseInput v-model="form.vat_number" placeholder="ДДС номер" />
    <BaseButton type="submit">Запази адрес</BaseButton>
  </form>
</template>

<script setup lang="ts">
const emit = defineEmits<{ saved: [] }>()
const api = useApi()
const form = reactive({
  type: 'shipping',
  first_name: '',
  last_name: '',
  company_name: '',
  vat_number: '',
  phone: '',
  country: 'Bulgaria',
  city: '',
  postcode: '',
  address_line_1: '',
  address_line_2: '',
  is_default: false,
})

async function submit() {
  await api.post('/auth/addresses', form)
  emit('saved')
}
</script>
