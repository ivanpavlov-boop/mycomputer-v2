<template>
  <form v-if="company" class="grid gap-4" @submit.prevent="submit">
    <BaseInput :model-value="company.name" placeholder="&#1060;&#1080;&#1088;&#1084;&#1072;" disabled />
    <BaseInput :model-value="company.vat_number" placeholder="&#1045;&#1048;&#1050; / &#1044;&#1044;&#1057; &#1085;&#1086;&#1084;&#1077;&#1088;" disabled />
    <BaseInput v-model="form.email" placeholder="&#1048;&#1084;&#1077;&#1081;&#1083;" />
    <BaseInput v-model="form.phone" placeholder="&#1058;&#1077;&#1083;&#1077;&#1092;&#1086;&#1085;" />
    <BaseInput v-model="form.website" placeholder="&#1059;&#1077;&#1073;&#1089;&#1072;&#1081;&#1090;" />
    <BaseInput v-model="form.billing_address" placeholder="&#1040;&#1076;&#1088;&#1077;&#1089; &#1079;&#1072; &#1092;&#1072;&#1082;&#1090;&#1091;&#1088;&#1080;&#1088;&#1072;&#1085;&#1077;" />
    <BaseInput v-model="form.shipping_address" placeholder="&#1040;&#1076;&#1088;&#1077;&#1089; &#1079;&#1072; &#1076;&#1086;&#1089;&#1090;&#1072;&#1074;&#1082;&#1072;" />
    <BaseButton type="submit">&#1047;&#1072;&#1087;&#1072;&#1079;&#1080;</BaseButton>
  </form>
</template>

<script setup lang="ts">
const props = defineProps<{ company: any }>()
const emit = defineEmits<{ saved: [] }>()
const b2b = useB2B()
const form = reactive({
  email: props.company?.email || '',
  phone: props.company?.phone || '',
  website: props.company?.website || '',
  billing_address: props.company?.billing_address || '',
  shipping_address: props.company?.shipping_address || '',
})

async function submit() {
  await b2b.updateCompany({ ...form })
  emit('saved')
}
</script>
