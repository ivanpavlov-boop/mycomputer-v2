<template>
  <footer class="mt-16 border-t border-slate-200 bg-slate-950 text-slate-200">
    <div class="container-page grid gap-8 py-10 md:grid-cols-4">
      <div>
        <p class="text-lg font-bold text-white">mycomputer.bg</p>
        <p class="mt-3 text-sm text-slate-400">Модерен онлайн магазин за компютърна техника, компоненти и аксесоари.</p>
      </div>
      <div>
        <p class="font-semibold text-white">Информация</p>
        <div class="mt-3 grid gap-2 text-sm">
          <NuxtLink to="/about">За нас</NuxtLink>
          <NuxtLink to="/contacts">Контакти</NuxtLink>
          <NuxtLink to="/delivery">Доставка</NuxtLink>
        </div>
      </div>
      <div>
        <p class="font-semibold text-white">Услуги</p>
        <div class="mt-3 grid gap-2 text-sm">
          <NuxtLink to="/warranty">Гаранция</NuxtLink>
          <NuxtLink to="/leasing">Лизинг</NuxtLink>
          <NuxtLink to="/service">Сервиз</NuxtLink>
        </div>
      </div>
      <div>
        <NewsletterSignup />
      </div>
    </div>
  </footer>
</template>
