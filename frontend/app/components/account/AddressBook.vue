<template>
  <section class="surface space-y-5 p-5">
    <h2 class="text-xl font-bold">Адреси</h2>
    <AddressForm @saved="load" />
    <div class="grid gap-3">
      <div v-for="address in addresses" :key="address.id" class="rounded-md border border-slate-200 p-4">
        <div class="flex items-start justify-between gap-3">
          <div>
            <p class="font-semibold">{{ address.first_name }} {{ address.last_name }}</p>
            <p class="text-sm text-slate-600">{{ address.city }}, {{ address.address_line_1 }}</p>
            <p class="text-xs text-slate-500">{{ address.type }} {{ address.is_default ? '· основен' : '' }}</p>
          </div>
          <BaseButton variant="ghost" @click="remove(address.id)">Изтрий</BaseButton>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
const api = useApi()
const addresses = ref<any[]>([])

async function load() {
  const response = await api.get<{ data: any[] }>('/auth/addresses')
  addresses.value = response.data
}

async function remove(id: number) {
  await api.destroy(`/auth/addresses/${id}`)
  await load()
}

await load()
</script>
