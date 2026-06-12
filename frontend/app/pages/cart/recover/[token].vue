<template>
  <div class="container-page py-10">
    <LoadingState v-if="pending" text="&#1042;&#1098;&#1079;&#1089;&#1090;&#1072;&#1085;&#1086;&#1074;&#1103;&#1074;&#1072;&#1085;&#1077; &#1085;&#1072; &#1082;&#1086;&#1083;&#1080;&#1095;&#1082;&#1072;&#1090;&#1072;..." />
    <ErrorState v-else-if="error" :text="error" />
    <div v-else class="surface p-6">
      <h1 class="text-2xl font-bold">&#1050;&#1086;&#1083;&#1080;&#1095;&#1082;&#1072;&#1090;&#1072; &#1077; &#1074;&#1098;&#1079;&#1089;&#1090;&#1072;&#1085;&#1086;&#1074;&#1077;&#1085;&#1072;</h1>
      <p class="mt-2 text-sm text-slate-600">&#1055;&#1088;&#1086;&#1076;&#1091;&#1082;&#1090;&#1080;&#1090;&#1077; &#1086;&#1090; &#1079;&#1072;&#1087;&#1072;&#1079;&#1077;&#1085;&#1072;&#1090;&#1072; &#1082;&#1086;&#1083;&#1080;&#1095;&#1082;&#1072; &#1089;&#1072; &#1076;&#1086;&#1073;&#1072;&#1074;&#1077;&#1085;&#1080; &#1086;&#1073;&#1088;&#1072;&#1090;&#1085;&#1086;.</p>
      <div class="mt-5 flex gap-3">
        <NuxtLink class="rounded-md bg-brand-600 px-4 py-2 text-sm font-semibold text-white" to="/cart">&#1050;&#1098;&#1084; &#1082;&#1086;&#1083;&#1080;&#1095;&#1082;&#1072;&#1090;&#1072;</NuxtLink>
        <NuxtLink class="rounded-md bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-800" to="/checkout">&#1050;&#1098;&#1084; &#1087;&#1086;&#1088;&#1098;&#1095;&#1082;&#1072;</NuxtLink>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
const route = useRoute()
const router = useRouter()
const cart = useCartStore()
const pending = ref(true)
const error = ref('')

try {
  const token = String(route.params.token || '')
  const api = useCartApi()
  const response = await api.recover(token) as { data: any }
  cart.backendCart = response.data
  await router.replace('/cart')
} catch {
  error.value = '\u041b\u0438\u043d\u043a\u044a\u0442 \u0437\u0430 \u0432\u044a\u0437\u0441\u0442\u0430\u043d\u043e\u0432\u044f\u0432\u0430\u043d\u0435 \u0435 \u043d\u0435\u0432\u0430\u043b\u0438\u0434\u0435\u043d \u0438\u043b\u0438 \u0435 \u0438\u0437\u0442\u0435\u043a\u044a\u043b.'
} finally {
  pending.value = false
}

useSeo().page(
  '\u0412\u044a\u0437\u0441\u0442\u0430\u043d\u043e\u0432\u044f\u0432\u0430\u043d\u0435 \u043d\u0430 \u043a\u043e\u043b\u0438\u0447\u043a\u0430',
  '\u0412\u044a\u0437\u0441\u0442\u0430\u043d\u043e\u0432\u044f\u0432\u0430\u043d\u0435 \u043d\u0430 \u0437\u0430\u043f\u0430\u0437\u0435\u043d\u0430 \u043a\u043e\u043b\u0438\u0447\u043a\u0430.',
  `/cart/recover/${route.params.token}`,
)
</script>
