<template>
  <section class="rounded-md border border-blue-200 bg-blue-50 p-4" aria-labelledby="leasing-application-title">
    <h3 id="leasing-application-title" class="font-semibold text-blue-950">Покупка на изплащане</h3>
    <p class="mt-2 text-sm text-blue-900">
      Изпратете заявка за покупка на изплащане. След получаването ѝ наш служител ще се свърже с Вас за уточняване на условията и следващите стъпки.
    </p>
    <p class="mt-2 text-sm font-medium text-blue-950">
      Изпращането на заявката не гарантира одобрение или конкретни условия за финансиране.
    </p>

    <div class="mt-4 grid gap-4 sm:grid-cols-2">
      <label class="text-sm font-medium text-slate-800">
        Желан срок
        <select
          class="mt-1 w-full rounded-md border border-slate-300 bg-white p-2.5"
          :value="modelValue.term_months ?? ''"
          required
          @change="update('term_months', Number(($event.target as HTMLSelectElement).value))"
        >
          <option v-for="term in options.term_months" :key="term" :value="term">{{ term }} месеца</option>
        </select>
        <span v-if="errors.term_months" class="mt-1 block text-xs text-red-700">{{ errors.term_months }}</span>
      </label>

      <label class="text-sm font-medium text-slate-800">
        Желана първоначална вноска
        <span class="relative mt-1 block">
          <input
            class="w-full rounded-md border border-slate-300 bg-white p-2.5 pr-14"
            type="number"
            min="0"
            step="0.01"
            :max="orderTotal"
            :value="modelValue.down_payment"
            required
            @input="update('down_payment', ($event.target as HTMLInputElement).value)"
          >
          <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-slate-500">{{ options.currency }}</span>
        </span>
        <span v-if="errors.down_payment" class="mt-1 block text-xs text-red-700">{{ errors.down_payment }}</span>
      </label>

      <label class="text-sm font-medium text-slate-800">
        Предпочитан начин за контакт
        <select
          class="mt-1 w-full rounded-md border border-slate-300 bg-white p-2.5"
          :value="modelValue.contact_method"
          required
          @change="update('contact_method', ($event.target as HTMLSelectElement).value)"
        >
          <option v-for="option in options.contact_methods" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
        <span v-if="errors.contact_method" class="mt-1 block text-xs text-red-700">{{ errors.contact_method }}</span>
      </label>

      <label class="text-sm font-medium text-slate-800">
        Предпочитано време за контакт
        <select
          class="mt-1 w-full rounded-md border border-slate-300 bg-white p-2.5"
          :value="modelValue.contact_time"
          @change="update('contact_time', ($event.target as HTMLSelectElement).value)"
        >
          <option v-for="option in options.contact_time_slots" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
        <span v-if="errors.contact_time" class="mt-1 block text-xs text-red-700">{{ errors.contact_time }}</span>
      </label>
    </div>

    <label class="mt-4 block text-sm font-medium text-slate-800">
      Коментар
      <textarea
        class="mt-1 w-full rounded-md border border-slate-300 bg-white p-3"
        rows="3"
        maxlength="1000"
        :value="modelValue.note"
        @input="update('note', ($event.target as HTMLTextAreaElement).value)"
      />
      <span v-if="errors.note" class="mt-1 block text-xs text-red-700">{{ errors.note }}</span>
    </label>

    <label class="mt-4 flex items-start gap-2 text-sm text-slate-800">
      <input
        class="mt-1"
        type="checkbox"
        :checked="modelValue.consent"
        @change="update('consent', ($event.target as HTMLInputElement).checked)"
      >
      <span>Съгласен/на съм данните от поръчката да бъдат използвани за обработване на заявката ми за покупка на изплащане и за контакт с мен.</span>
    </label>
    <span v-if="errors.consent" class="mt-1 block text-xs text-red-700">{{ errors.consent }}</span>
  </section>
</template>

<script setup lang="ts">
import type { LeasingPaymentOptions } from '~/types/api'
import type { LeasingApplicationErrors, LeasingApplicationForm } from '~/utils/leasingCheckout'

const props = defineProps<{
  modelValue: LeasingApplicationForm
  options: LeasingPaymentOptions
  orderTotal: number
  errors: LeasingApplicationErrors
}>()

const emit = defineEmits<{ 'update:modelValue': [value: LeasingApplicationForm] }>()

function update<Key extends keyof LeasingApplicationForm>(
  key: Key,
  value: LeasingApplicationForm[Key],
) {
  emit('update:modelValue', {
    ...props.modelValue,
    [key]: value,
  })
}
</script>
