<template>
  <aside class="surface h-fit p-4">
    <nav class="grid gap-1 text-sm font-semibold">
      <NuxtLink class="rounded-md px-3 py-2 hover:bg-slate-100" to="/account">Профил</NuxtLink>
      <NuxtLink class="rounded-md px-3 py-2 hover:bg-slate-100" to="/account/profile">Данни</NuxtLink>
      <NuxtLink class="rounded-md px-3 py-2 hover:bg-slate-100" to="/account/addresses">Адреси</NuxtLink>
      <NuxtLink class="rounded-md px-3 py-2 hover:bg-slate-100" to="/account/orders">Поръчки</NuxtLink>
      <NuxtLink class="rounded-md px-3 py-2 hover:bg-slate-100" to="/account/service">Сервиз и гаранция</NuxtLink>
      <NuxtLink class="rounded-md px-3 py-2 hover:bg-slate-100" to="/account/loyalty">Лоялност</NuxtLink>
      <NuxtLink class="rounded-md px-3 py-2 hover:bg-slate-100" to="/account/rewards">Награди</NuxtLink>
      <NuxtLink class="rounded-md px-3 py-2 hover:bg-slate-100" to="/account/pc-builds">PC конфигурации</NuxtLink>
      <NuxtLink class="rounded-md px-3 py-2 hover:bg-slate-100" to="/account/reviews">Ревюта</NuxtLink>
      <NuxtLink class="rounded-md px-3 py-2 hover:bg-slate-100" to="/account/wishlist">Любими</NuxtLink>
      <NuxtLink class="rounded-md px-3 py-2 hover:bg-slate-100" to="/account/wishlists">Списъци</NuxtLink>
      <NuxtLink class="rounded-md px-3 py-2 hover:bg-slate-100" to="/account/preferences">Предпочитания</NuxtLink>
      <NuxtLink class="rounded-md px-3 py-2 hover:bg-slate-100" to="/account/assistant-history">AI история</NuxtLink>
      <NuxtLink class="rounded-md px-3 py-2 hover:bg-slate-100" to="/account/security">Сигурност</NuxtLink>
      <button class="rounded-md px-3 py-2 text-left text-red-700 hover:bg-red-50" @click="logout">Изход</button>
    </nav>
  </aside>
</template>

<script setup lang="ts">
const auth = useAuthStore()
const router = useRouter()

async function logout() {
  await auth.logout()
  await router.push('/login')
}
</script>
