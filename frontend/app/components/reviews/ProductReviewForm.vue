<template>
  <form class="surface grid gap-4 p-4" @submit.prevent="submit">
    <h3 class="text-lg font-semibold">Напишете ревю</h3>
    <label class="grid gap-1 text-sm font-semibold">
      Оценка
      <BaseSelect v-model="rating" required>
        <option v-for="option in ratingOptions" :key="option" :value="option">{{ option }}</option>
      </BaseSelect>
    </label>
    <label class="grid gap-1 text-sm font-semibold">
      Заглавие
      <BaseInput v-model="title" />
    </label>
    <textarea v-model="comment" class="rounded-md border border-slate-300 p-3" rows="4" placeholder="Вашето мнение" required />
    <textarea v-model="pros" class="rounded-md border border-slate-300 p-3" rows="2" placeholder="Плюсове" />
    <textarea v-model="cons" class="rounded-md border border-slate-300 p-3" rows="2" placeholder="Минуси" />
    <div v-if="!auth.isAuthenticated" class="grid gap-3 md:grid-cols-2">
      <label class="grid gap-1 text-sm font-semibold">
        Име
        <BaseInput v-model="customerName" required />
      </label>
      <label class="grid gap-1 text-sm font-semibold">
        Имейл
        <BaseInput v-model="customerEmail" type="email" required />
      </label>
    </div>
    <BaseButton type="submit">Изпрати за одобрение</BaseButton>
    <p v-if="message" class="text-sm text-emerald-700">{{ message }}</p>
    <p v-if="error" class="text-sm text-red-700">{{ error }}</p>
  </form>
</template>

<script setup lang="ts">
const props = defineProps<{ productSlug: string }>()
const emit = defineEmits<{ submitted: [] }>()
const auth = useAuthStore()
const reviews = useReviews()
const rating = ref(5)
const title = ref('')
const comment = ref('')
const pros = ref('')
const cons = ref('')
const customerName = ref('')
const customerEmail = ref('')
const message = ref('')
const error = ref('')
const ratingOptions = [1, 2, 3, 4, 5]

async function submit() {
  message.value = ''
  error.value = ''
  try {
    await reviews.submit(props.productSlug, {
      rating: Number(rating.value),
      title: title.value || undefined,
      comment: comment.value,
      pros: pros.value || undefined,
      cons: cons.value || undefined,
      customer_name: customerName.value || undefined,
      customer_email: customerEmail.value || undefined,
    })
    message.value = 'Ревюто е изпратено и очаква одобрение.'
    emit('submitted')
  } catch {
    error.value = 'Ревюто не беше изпратено. Проверете данните и опитайте отново.'
  }
}
</script>
