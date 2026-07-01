<template>
  <div class="min-h-screen bg-slate-50">
    <AppHeader />
    <main>
      <slot />
    </main>
    <AppFooter />
    <CartDrawer v-if="!isReadOnlyStorefrontRoute" />
  </div>
</template>

<script setup lang="ts">
const isReadOnlyStorefrontRoute = useReadOnlyStorefrontRoute()
</script>
