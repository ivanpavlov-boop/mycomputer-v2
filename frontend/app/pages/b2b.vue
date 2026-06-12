<template>
  <div class="container-page py-10">
    <section class="grid gap-6 lg:grid-cols-[1fr_360px]">
      <div>
        <h1 class="text-3xl font-bold text-slate-950">B2B портал</h1>
        <p class="mt-3 max-w-2xl text-slate-600">
          Корпоративни профили, заявки за оферти, покупки на едро и бъдеща интеграция с ERP цени.
        </p>
        <div class="mt-6 flex gap-3">
          <NuxtLink to="/b2b/apply" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white">Кандидатствай</NuxtLink>
          <NuxtLink to="/account/b2b/quotes" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold">Моите оферти</NuxtLink>
        </div>
      </div>
      <div class="surface p-5">
        <h2 class="font-semibold">За бизнес клиенти</h2>
        <ul class="mt-3 grid gap-2 text-sm text-slate-600">
          <li>Заявки за индивидуални оферти</li>
          <li>Фирмен профил и роли</li>
          <li>Бъдеща ERP и B2B ценова интеграция</li>
        </ul>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
useSeo().page('B2B портал', 'B2B услуги и заявки за оферти от mycomputer.bg.', '/b2b')
</script>
