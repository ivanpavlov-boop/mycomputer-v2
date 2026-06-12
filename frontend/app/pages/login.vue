<template>
  <div class="container-page py-10">
    <LoginForm />
    <p class="mt-4 text-center text-sm text-slate-600">
      Нямаш профил?
      <NuxtLink class="font-semibold text-brand-700" to="/register">Регистрация</NuxtLink>
    </p>
  </div>
</template>

<script setup lang="ts">
useSeo().page('Вход', 'Вход в клиентски профил.', '/login')
</script>
