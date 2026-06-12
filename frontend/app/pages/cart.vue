<template>
  <div>
    <Breadcrumbs :items="[{ label: '&#1050;&#1086;&#1083;&#1080;&#1095;&#1082;&#1072;' }]" />
    <div class="container-page">
      <h1 class="text-3xl font-bold">&#1050;&#1086;&#1083;&#1080;&#1095;&#1082;&#1072;</h1>
      <div class="mt-6 grid gap-6 lg:grid-cols-[1fr_320px]">
        <div class="surface p-4">
          <CartItem
            v-for="item in cart.backendItems"
            :key="item.id"
            :line="{ product: item.product, quantity: item.quantity }"
            :item-id="item.id"
          />
          <CartBundleItem
            v-for="item in cart.backendCart?.bundle_items || []"
            :key="item.id"
            :item="item"
          />
          <CartItem v-for="line in cart.lines" :key="line.product.id" :line="line" />
          <EmptyState
            v-if="!cart.backendItems.length && !(cart.backendCart?.bundle_items || []).length && !cart.lines.length"
            title="&#1050;&#1086;&#1083;&#1080;&#1095;&#1082;&#1072;&#1090;&#1072; &#1077; &#1087;&#1088;&#1072;&#1079;&#1085;&#1072;"
            text="&#1042;&#1089;&#1077; &#1086;&#1097;&#1077; &#1085;&#1103;&#1084;&#1072; &#1076;&#1086;&#1073;&#1072;&#1074;&#1077;&#1085;&#1080; &#1087;&#1088;&#1086;&#1076;&#1091;&#1082;&#1090;&#1080;."
          />
        </div>
        <aside class="surface h-fit p-5">
          <p class="text-lg font-semibold">&#1054;&#1073;&#1086;&#1073;&#1097;&#1077;&#1085;&#1080;&#1077;</p>
          <div class="mt-4 flex justify-between">
            <span>&#1052;&#1077;&#1078;&#1076;&#1080;&#1085;&#1085;&#1072; &#1089;&#1091;&#1084;&#1072;</span>
            <span class="font-semibold">{{ cart.subtotal.toFixed(2) }} &#1083;&#1074;.</span>
          </div>
          <NuxtLink to="/checkout" class="mt-5 block rounded-md bg-brand-600 px-4 py-2 text-center text-sm font-semibold text-white">
            &#1050;&#1098;&#1084; &#1087;&#1086;&#1088;&#1098;&#1095;&#1082;&#1072;
          </NuxtLink>
          <BaseButton class="mt-3 w-full" variant="secondary" @click="requestQuote">
            &#1047;&#1072;&#1103;&#1074;&#1080; &#1086;&#1092;&#1077;&#1088;&#1090;&#1072; &#1079;&#1072; &#1082;&#1086;&#1083;&#1080;&#1095;&#1082;&#1072;&#1090;&#1072;
          </BaseButton>
        </aside>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
const cart = useCartStore()
const b2b = useB2B()
const router = useRouter()
const auth = useAuthStore()

await cart.sync()

async function requestQuote() {
  await auth.fetchUser()
  if (!auth.isAuthenticated) {
    await router.push('/login')
    return
  }
  const response = await b2b.requestCartQuote({ notes: '\u0417\u0430\u044f\u0432\u043a\u0430 \u0437\u0430 \u043e\u0444\u0435\u0440\u0442\u0430 \u043e\u0442 \u043a\u043e\u043b\u0438\u0447\u043a\u0430' }) as any
  await router.push(`/account/b2b/quotes/${response.data.id}`)
}

useSeo().page('\u041a\u043e\u043b\u0438\u0447\u043a\u0430', '\u041f\u0440\u0435\u0433\u043b\u0435\u0434 \u043d\u0430 \u0438\u0437\u0431\u0440\u0430\u043d\u0438\u0442\u0435 \u043f\u0440\u043e\u0434\u0443\u043a\u0442\u0438.', '/cart')
</script>
