<template>
  <div class="container-page py-8">
    <div class="grid gap-6 lg:grid-cols-[260px_1fr]">
      <AccountSidebar />
      <OrderHistoryTable v-if="auth.isAuthenticated" />
    </div>
  </div>
</template>

<script setup lang="ts">
const auth = useAuthStore()
const router = useRouter()
onMounted(async () => {
  await auth.fetchUser()
  if (!auth.isAuthenticated) await router.push('/login')
})
useSeo().page('Поръчки', 'История на поръчките.', '/account/orders')
</script>
