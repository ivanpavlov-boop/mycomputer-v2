<template>
  <div>
    <Breadcrumbs :items="[{ label: 'Профил', to: '/account' }, { label: 'Любими продукти' }]" />
    <div class="container-page grid gap-8 lg:grid-cols-[260px_1fr]">
      <AccountSidebar />
      <section>
        <h1 class="text-2xl font-bold">Любими продукти</h1>
        <LoadingState v-if="wishlist.loading" class="mt-6" />
        <EmptyState v-else-if="!wishlist.defaultWishlist?.items?.length" class="mt-6" title="Нямате любими продукти" text="Добавете продукти чрез бутона със сърце в каталога." />
        <ProductGrid v-else class="mt-6" :products="defaultProducts" />
      </section>
    </div>
  </div>
</template>

<script setup lang="ts">
import type { ProductCard } from '~/types/api'

definePageMeta({ middleware: 'auth' })

const wishlist = useWishlistStore()
await wishlist.load()
const defaultProducts = computed<ProductCard[]>(() => wishlist.defaultWishlist?.items?.map((item) => item.product).filter((product): product is ProductCard => Boolean(product)) || [])
useSeo().page('Любими продукти', 'Вашите запазени продукти.', '/account/wishlist')
</script>
