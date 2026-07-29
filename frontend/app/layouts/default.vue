<template>
  <div class="min-h-screen bg-slate-50">
    <LayoutAppHeader />
    <main>
      <slot />
    </main>
    <LayoutAppFooter />
    <CartDrawer v-if="canStartCheckout" />
  </div>
</template>

<script setup lang="ts">
const { canStartCheckout } = useCommerceReleaseGate()
</script>
