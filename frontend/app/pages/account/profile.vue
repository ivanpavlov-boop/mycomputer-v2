<template>
  <div class="container-page py-8">
    <div class="grid gap-6 lg:grid-cols-[260px_1fr]">
      <AccountSidebar />
      <ProfileForm v-if="auth.user" />
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
useSeo().page('Данни на профила', 'Редакция на клиентски данни.', '/account/profile')
</script>
