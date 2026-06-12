<template>
  <div class="grid gap-3">
    <div v-for="(item, index) in model" :key="index" class="grid gap-2 rounded-md border border-slate-200 p-3 md:grid-cols-4">
      <BaseInput v-model="item.product_id" placeholder="ID &#1085;&#1072; &#1087;&#1088;&#1086;&#1076;&#1091;&#1082;&#1090;" />
      <BaseInput v-model="item.quantity" placeholder="&#1050;&#1086;&#1083;&#1080;&#1095;&#1077;&#1089;&#1090;&#1074;&#1086;" type="number" />
      <BaseInput v-model="item.requested_price" placeholder="&#1046;&#1077;&#1083;&#1072;&#1085;&#1072; &#1094;&#1077;&#1085;&#1072;" type="number" />
      <BaseInput v-model="item.notes" placeholder="&#1041;&#1077;&#1083;&#1077;&#1078;&#1082;&#1072;" />
    </div>
    <BaseButton variant="secondary" @click="model.push({ product_id: '', quantity: 1, requested_price: null, notes: '' })">&#1044;&#1086;&#1073;&#1072;&#1074;&#1080; &#1088;&#1077;&#1076;</BaseButton>
  </div>
</template>

<script setup lang="ts">
const model = defineModel<any[]>({ required: true })
</script>
