<template>
  <form class="grid gap-4" @submit.prevent="submit">
    <BaseInput v-model="form.company_name" placeholder="&#1048;&#1084;&#1077; &#1085;&#1072; &#1092;&#1080;&#1088;&#1084;&#1072;" required />
    <BaseInput v-model="form.vat_number" placeholder="&#1045;&#1048;&#1050; / &#1044;&#1044;&#1057; &#1085;&#1086;&#1084;&#1077;&#1088;" required />
    <BaseInput v-model="form.mol" placeholder="&#1052;&#1054;&#1051;" />
    <BaseInput v-model="form.email" placeholder="&#1048;&#1084;&#1077;&#1081;&#1083;" />
    <BaseInput v-model="form.phone" placeholder="&#1058;&#1077;&#1083;&#1077;&#1092;&#1086;&#1085;" />
    <BaseInput v-model="form.billing_address" placeholder="&#1040;&#1076;&#1088;&#1077;&#1089; &#1079;&#1072; &#1092;&#1072;&#1082;&#1090;&#1091;&#1088;&#1080;&#1088;&#1072;&#1085;&#1077;" required />
    <BaseInput v-model="form.shipping_address" placeholder="&#1040;&#1076;&#1088;&#1077;&#1089; &#1079;&#1072; &#1076;&#1086;&#1089;&#1090;&#1072;&#1074;&#1082;&#1072;" />
    <BaseButton type="submit">&#1048;&#1079;&#1087;&#1088;&#1072;&#1090;&#1080; &#1079;&#1072;&#1103;&#1074;&#1082;&#1072;</BaseButton>
    <p v-if="message" class="text-sm text-emerald-700">{{ message }}</p>
  </form>
</template>

<script setup lang="ts">
const b2b = useB2B()
const form = reactive({
  company_name: '',
  vat_number: '',
  mol: '',
  email: '',
  phone: '',
  billing_address: '',
  shipping_address: '',
})
const message = ref('')

async function submit() {
  await b2b.apply({ ...form })
  message.value = '\u0417\u0430\u044f\u0432\u043a\u0430\u0442\u0430 \u0435 \u0438\u0437\u043f\u0440\u0430\u0442\u0435\u043d\u0430 \u0438 \u043e\u0447\u0430\u043a\u0432\u0430 \u043e\u0434\u043e\u0431\u0440\u0435\u043d\u0438\u0435.'
}
</script>
