<template>
  <div class="container-page py-8">
    <div class="grid gap-6 lg:grid-cols-[260px_1fr]">
      <AccountSidebar />
      <main class="surface p-6">
        <h1 class="text-2xl font-bold">Нова сервизна заявка</h1>
        <form class="mt-6 grid gap-4" @submit.prevent="submit">
          <label class="grid gap-1 text-sm font-semibold">
            <span>Тип заявка</span>
            <BaseSelect v-model="form.ticket_type">
              <option v-for="option in typeOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </BaseSelect>
          </label>
          <label class="grid gap-1 text-sm font-semibold">
            <span>Номер на поръчка ID</span>
            <BaseInput v-model="form.order_id" type="number" />
          </label>
          <label class="grid gap-1 text-sm font-semibold">
            <span>Продукт ID</span>
            <BaseInput v-model="form.product_id" type="number" />
          </label>
          <label class="grid gap-1 text-sm font-semibold">
            <span>Тема</span>
            <BaseInput v-model="form.subject" required />
          </label>
          <label class="grid gap-1 text-sm font-semibold">
            <span>Сериен номер</span>
            <BaseInput v-model="form.serial_number" />
          </label>
          <textarea v-model="form.description" class="min-h-32 rounded-md border border-slate-300 p-3" placeholder="Описание на проблема" required />
          <BaseButton type="submit">Изпрати заявка</BaseButton>
        </form>
      </main>
    </div>
  </div>
</template>

<script setup lang="ts">
const service = useServiceTickets()
const router = useRouter()

const form = reactive({
  ticket_type: 'warranty_claim',
  order_id: '',
  product_id: '',
  subject: '',
  serial_number: '',
  description: '',
})

const typeOptions = [
  { label: 'Гаранционна рекламация', value: 'warranty_claim' },
  { label: 'Сервизна заявка', value: 'service_request' },
  { label: 'Връщане', value: 'return_request' },
  { label: 'DOA заявка', value: 'doa_request' },
]

async function submit() {
  const response = await service.create({
    ...form,
    order_id: form.order_id ? Number(form.order_id) : undefined,
    product_id: form.product_id ? Number(form.product_id) : undefined,
  })
  await router.push(`/account/service/${response.data.id}`)
}

useSeo().page('Нова сервизна заявка', 'Създаване на сервизна или гаранционна заявка.', '/account/service/new')
</script>
