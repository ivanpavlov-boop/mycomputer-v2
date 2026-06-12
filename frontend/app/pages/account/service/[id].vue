<template>
  <div class="container-page py-8">
    <div class="grid gap-6 lg:grid-cols-[260px_1fr]">
      <AccountSidebar />
      <main v-if="ticket" class="grid gap-6">
        <section class="surface p-6">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p class="text-xs font-semibold uppercase text-slate-500">{{ ticket.ticket_number }}</p>
              <h1 class="mt-1 text-2xl font-bold">{{ ticket.subject }}</h1>
              <p class="mt-2 text-slate-600">{{ ticket.description }}</p>
            </div>
            <WarrantyStatusBadge :warranty="ticket.warranty" />
          </div>
          <div class="mt-4 grid gap-2 text-sm text-slate-700 md:grid-cols-2">
            <p>Статус: <strong>{{ ticket.status.replaceAll('_', ' ') }}</strong></p>
            <p>Приоритет: <strong>{{ ticket.priority }}</strong></p>
            <p v-if="ticket.product">Продукт: <strong>{{ ticket.product.name }}</strong></p>
            <p v-if="ticket.order">Поръчка: <strong>{{ ticket.order.order_number }}</strong></p>
          </div>
          <BaseButton v-if="!ticket.closed_at" class="mt-5" variant="secondary" @click="closeTicket">Затвори заявката</BaseButton>
        </section>
        <div class="grid gap-6 lg:grid-cols-[1fr_320px]">
          <ServiceTicketMessages :messages="ticket.messages || []" @send="sendMessage" />
          <div class="grid gap-6">
            <ServiceTicketTimeline :ticket="ticket" />
            <ServiceTicketFiles :files="ticket.files || []" @upload="uploadFile" />
          </div>
        </div>
      </main>
    </div>
  </div>
</template>

<script setup lang="ts">
const route = useRoute()
const service = useServiceTickets()
const id = String(route.params.id)
const { data, refresh } = await useAsyncData(`service-ticket-${id}`, () => service.show(id))
const ticket = computed(() => data.value?.data)

async function sendMessage(message: string) {
  await service.message(id, { message })
  await refresh()
}

async function uploadFile(file: File) {
  const form = new FormData()
  form.append('file', file)
  await service.upload(id, form)
  await refresh()
}

async function closeTicket() {
  await service.close(id)
  await refresh()
}

watchEffect(() => {
  if (ticket.value) {
    useSeo().page(ticket.value.subject, ticket.value.description, `/account/service/${ticket.value.id}`)
  }
})
</script>
