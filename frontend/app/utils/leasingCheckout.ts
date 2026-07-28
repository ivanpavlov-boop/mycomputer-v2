import type { LeasingPaymentOptions } from '~/types/api'

export interface LeasingApplicationForm {
  term_months: number | null
  down_payment: string
  contact_method: string
  contact_time: string
  note: string
  consent: boolean
}

export type LeasingApplicationErrors = Partial<Record<keyof LeasingApplicationForm, string>>

export function createLeasingApplicationForm(options?: LeasingPaymentOptions): LeasingApplicationForm {
  return {
    term_months: options?.term_months[0] ?? null,
    down_payment: '0.00',
    contact_method: options?.contact_methods[0]?.value ?? '',
    contact_time: options?.contact_time_slots[0]?.value ?? '',
    note: '',
    consent: false,
  }
}

export function applyLeasingOptions(
  form: LeasingApplicationForm,
  options?: LeasingPaymentOptions,
): void {
  if (!options) {
    return
  }

  if (!options.term_months.includes(Number(form.term_months))) {
    form.term_months = options.term_months[0] ?? null
  }

  if (!options.contact_methods.some(option => option.value === form.contact_method)) {
    form.contact_method = options.contact_methods[0]?.value ?? ''
  }

  if (!options.contact_time_slots.some(option => option.value === form.contact_time)) {
    form.contact_time = options.contact_time_slots[0]?.value ?? ''
  }
}

export function validateLeasingApplication(
  form: LeasingApplicationForm,
  options: LeasingPaymentOptions | undefined,
  orderTotal: number,
): LeasingApplicationErrors {
  const errors: LeasingApplicationErrors = {}

  if (!options?.term_months.includes(Number(form.term_months))) {
    errors.term_months = 'Изберете поддържан срок.'
  }

  if (!/^\d+(?:\.\d{1,2})?$/.test(form.down_payment)) {
    errors.down_payment = 'Въведете неотрицателна сума с най-много два знака след десетичната точка.'
  } else if (Number(form.down_payment) > orderTotal) {
    errors.down_payment = 'Първоначалната вноска не може да надвишава общата сума.'
  }

  if (!options?.contact_methods.some(option => option.value === form.contact_method)) {
    errors.contact_method = 'Изберете поддържан начин за контакт.'
  }

  if (
    form.contact_time
    && !options?.contact_time_slots.some(option => option.value === form.contact_time)
  ) {
    errors.contact_time = 'Изберете поддържано време за контакт.'
  }

  if (form.note.length > 1000) {
    errors.note = 'Коментарът може да съдържа най-много 1000 знака.'
  }

  if (!form.consent) {
    errors.consent = 'Необходимо е съгласие за обработване на заявката.'
  }

  return errors
}

export function withLeasingApplication(
  checkout: Record<string, unknown>,
  paymentMethod: string,
  form: LeasingApplicationForm,
): Record<string, unknown> {
  if (paymentMethod !== 'leasing') {
    return { ...checkout }
  }

  return {
    ...checkout,
    leasing_application: {
      term_months: Number(form.term_months),
      down_payment: form.down_payment,
      contact_method: form.contact_method,
      contact_time: form.contact_time || null,
      note: form.note.trim() || null,
      consent: form.consent,
    },
  }
}
