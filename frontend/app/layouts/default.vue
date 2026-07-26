<template>
  <div class="min-h-screen bg-slate-50">
    <LayoutAppHeader />
    <main>
      <slot />
    </main>
    <LayoutAppFooter />
    <CartDrawer v-if="!isReadOnlyStorefrontRoute" />
  </div>
</template>

<script setup lang="ts">
const isReadOnlyStorefrontRoute = useReadOnlyStorefrontRoute()
</script>
