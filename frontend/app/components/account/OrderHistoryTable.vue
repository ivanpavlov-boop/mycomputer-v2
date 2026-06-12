<template>
  <section class="surface p-5">
    <h2 class="text-xl font-bold">Поръчки</h2>
    <div class="mt-4 overflow-x-auto">
      <table class="w-full text-left text-sm">
        <thead class="border-b text-slate-500">
          <tr><th class="py-2">Номер</th><th>Статус</th><th>Плащане</th><th>Сума</th><th>Дата</th></tr>
        </thead>
        <tbody>
          <tr v-for="order in orders" :key="order.id" class="border-b">
            <td class="py-3 font-semibold">{{ order.order_number }}</td>
            <td>{{ order.status }}</td>
            <td>{{ order.payment_status }}</td>
            <td>{{ order.grand_total }} лв.</td>
            <td>{{ new Date(order.created_at).toLocaleDateString('bg-BG') }}</td>
          </tr>
        </tbody>
      </table>
      <EmptyState v-if="!orders.length" title="Няма поръчки" text="Историята на поръчките ще се появи тук." />
    </div>
  </section>
</template>

<script setup lang="ts">
const api = useApi()
const response = await api.get<{ data: { data: any[] } }>('/account/orders')
const orders = computed(() => response.data.data || [])
</script>
