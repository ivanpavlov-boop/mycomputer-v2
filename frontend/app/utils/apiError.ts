export const GENERIC_API_ERROR_MESSAGE = 'Възникна проблем. Моля, опитайте отново.'

const SAFE_ERROR_MESSAGES: Record<string, string> = {
  invalid_cart_session: 'Сесията на количката е невалидна. Моля, опитайте отново.',
  invalid_cart_session_response: 'Получен е невалиден отговор за количката. Моля, опитайте отново.',
  cart_product_unavailable: 'Продуктът вече не е наличен за покупка.',
  cart_quantity_unavailable: 'Заявеното количество не е налично.',
  cart_not_ready: 'Количката изисква преглед, преди да продължите.',
  cart_price_changed: 'Цената е променена. Прегледайте количката и опитайте отново.',
  cart_promotion_changed: 'Условията на промоцията са променени. Прегледайте количката.',
  cart_mutation_conflict: 'Количката е променена от друга заявка. Обновете и опитайте отново.',
  cart_gift_line_immutable: 'Подаръчният продукт се управлява от промоцията и не може да бъде променян.',
  cart_recovery_consumed: 'Този линк за възстановяване вече е използван.',
  cart_recovery_invalid: 'Линкът за възстановяване е невалиден или е изтекъл.',
  cart_recovery_forbidden: 'Тази количка не може да бъде възстановена за текущия потребител.',
  cart_recovery_requires_review: 'Количката е възстановена, но трябва да бъде прегледана.',
  validation_error: 'Проверете въведените данни и опитайте отново.',
  unauthenticated: 'Необходимо е да влезете в профила си.',
  forbidden: 'Нямате право да извършите това действие.',
}

export interface NormalizedApiError {
  statusCode: number | null
  code: string
  message: string
  details: Record<string, unknown> | null
  retryable: boolean
}

export class CartApiError extends Error implements NormalizedApiError {
  readonly statusCode: number | null
  readonly code: string
  readonly details: Record<string, unknown> | null
  readonly retryable: boolean

  constructor(error: NormalizedApiError) {
    super(error.message)
    this.name = 'CartApiError'
    this.statusCode = error.statusCode
    this.code = error.code
    this.details = error.details
    this.retryable = error.retryable
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function numericStatus(value: unknown): number | null {
  return typeof value === 'number' && Number.isInteger(value) ? value : null
}

function safeDetails(value: unknown): Record<string, unknown> | null {
  if (!isRecord(value)) {
    return null
  }

  const details = Object.fromEntries(
    Object.entries(value)
      .filter(([key, item]) => {
        const sensitive = /(token|session|authorization|password|secret|header)/i.test(key)
        const safeValue = item === null || ['boolean', 'number'].includes(typeof item)

        return !sensitive && safeValue
      }),
  )

  return Object.keys(details).length ? details : null
}

function errorPayload(error: unknown): Record<string, unknown> {
  if (!isRecord(error)) {
    return {}
  }

  const response = isRecord(error.response) ? error.response : {}
  const responseData = isRecord(response._data) ? response._data : {}
  const data = isRecord(error.data) ? error.data : responseData
  const nested = isRecord(data.error) ? data.error : data

  return {
    statusCode: numericStatus(error.statusCode)
      ?? numericStatus(error.status)
      ?? numericStatus(response.status),
    code: typeof nested.code === 'string' ? nested.code : '',
    details: nested.details,
  }
}

export function normalizeApiError(error: unknown): CartApiError {
  if (error instanceof CartApiError) {
    return error
  }

  const payload = errorPayload(error)
  const code = typeof payload.code === 'string' && payload.code ? payload.code : 'request_failed'
  const statusCode = numericStatus(payload.statusCode)

  return new CartApiError({
    statusCode,
    code,
    message: SAFE_ERROR_MESSAGES[code] ?? GENERIC_API_ERROR_MESSAGE,
    details: safeDetails(payload.details),
    retryable: statusCode === null || statusCode >= 500 || code === 'cart_mutation_conflict',
  })
}

export function invalidCartSessionResponseError(): CartApiError {
  return new CartApiError({
    statusCode: null,
    code: 'invalid_cart_session_response',
    message: SAFE_ERROR_MESSAGES.invalid_cart_session_response,
    details: null,
    retryable: true,
  })
}
