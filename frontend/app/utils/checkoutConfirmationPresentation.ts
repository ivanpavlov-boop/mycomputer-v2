const ORDER_STATUS_LABELS = {
  pending: 'Очаква обработка',
  confirmed: 'Потвърдена',
  processing: 'Обработва се',
  shipped: 'Изпратена',
  completed: 'Завършена',
  cancelled: 'Отказана',
  refunded: 'Възстановена',
} as const

const PAYMENT_METHOD_LABELS = {
  cash_on_delivery: 'Наложен платеж',
  bank_transfer: 'Банков превод',
  card: 'Плащане с карта',
  leasing: 'Покупка на изплащане',
} as const

const ORDER_STATUS_FALLBACK = 'Статусът се актуализира'
const PAYMENT_METHOD_FALLBACK = 'Избран начин на плащане'

export function orderStatusLabel(status: unknown): string {
  if (typeof status !== 'string') {
    return ORDER_STATUS_FALLBACK
  }

  return ORDER_STATUS_LABELS[status as keyof typeof ORDER_STATUS_LABELS]
    ?? ORDER_STATUS_FALLBACK
}

export function paymentMethodLabel(code: unknown, providedName?: unknown): string {
  if (typeof code === 'string') {
    const knownLabel = PAYMENT_METHOD_LABELS[code as keyof typeof PAYMENT_METHOD_LABELS]

    if (knownLabel) {
      return knownLabel
    }
  }

  if (isCustomerFacingPaymentMethodName(providedName, code)) {
    return providedName.trim()
  }

  return PAYMENT_METHOD_FALLBACK
}

function isCustomerFacingPaymentMethodName(name: unknown, code: unknown): name is string {
  if (typeof name !== 'string') {
    return false
  }

  const trimmedName = name.trim()
  const normalizedCode = typeof code === 'string' ? code.trim().toLowerCase() : ''

  return trimmedName.length > 0
    && trimmedName.length <= 100
    && !/[\u0000-\u001F\u007F]/u.test(trimmedName)
    && trimmedName.toLowerCase() !== normalizedCode
    && !/^[a-z0-9._-]+$/iu.test(trimmedName)
}
