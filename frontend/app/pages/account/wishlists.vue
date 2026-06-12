<template>
  <div>
    <Breadcrumbs :items="[{ label: 'Профил', to: '/account' }, { label: 'Списъци с любими' }]" />
    <div class="container-page grid gap-8 lg:grid-cols-[260px_1fr]">
      <AccountSidebar />
      <section class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h1 class="text-2xl font-bold">Списъци с любими</h1>
          <form class="flex gap-2" @submit.prevent="createWishlist">
            <BaseInput v-model="newName" placeholder="Име на списък" />
            <BaseButton type="submit">Създай</BaseButton>
          </form>
        </div>
        <LoadingState v-if="wishlist.loading" />
        <div v-else class="space-y-4">
          <article v-for="list in wishlist.wishlists" :key="list.id" class="surface p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
              <div>
                <h2 class="font-semibold">{{ list.name }}</h2>
                <p class="text-sm text-slate-500">{{ list.items_count || list.items?.length || 0 }} продукта</p>
              </div>
              <div class="flex gap-2">
                <BaseButton variant="secondary" @click="renameWishlist(list.id)">Преименувай</BaseButton>
                <BaseButton v-if="!list.is_default" variant="secondary" @click="wishlist.removeWishlist(list.id)">Изтрий</BaseButton>
              </div>
            </div>
          </article>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup lang="ts">
definePageMeta({ middleware: 'auth' })

const wishlist = useWishlistStore()
const newName = ref('')
await wishlist.load()

async function createWishlist() {
  if (!newName.value.trim()) return
  await wishlist.create({ name: newName.value.trim() })
  newName.value = ''
}

async function renameWishlist(id: number) {
  if (!import.meta.client) return
  const nextName = window.prompt('Ново име на списъка')
  if (nextName?.trim()) await wishlist.rename(id, nextName.trim())
}

useSeo().page('Списъци с любими', 'Управление на вашите списъци с любими продукти.', '/account/wishlists')
</script>
