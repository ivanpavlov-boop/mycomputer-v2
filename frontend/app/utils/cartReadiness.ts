import type { CartIssue, CartLineReadiness } from '~/types/api'

const ISSUE_MESSAGES: Record<string, string> = {
  cart_inactive: 'Количката вече не е активна.',
  cart_no_paid_items: 'Количката не съдържа продукти за поръчка.',
  product_missing: 'Продуктът вече не е наличен.',
  product_deleted: 'Продуктът вече не е наличен.',
  product_inactive: 'Продуктът временно не може да бъде поръчан.',
  product_unpublished: 'Продуктът временно не може да бъде поръчан.',
  product_status_inactive: 'Продуктът временно не може да бъде поръчан.',
  product_slug_missing: 'Продуктът временно не може да бъде поръчан.',
  product_category_unavailable: 'Продуктът временно не може да бъде поръчан.',
  product_purchase_disabled: 'Поръчването на този продукт е временно недостъпно.',
  insufficient_stock: 'Заявеното количество не е налично.',
  bundle_unavailable: 'Комплектът вече не е наличен.',
  bundle_selection_invalid: 'Избраната конфигурация на комплекта вече не е налична.',
  bundle_product_unavailable: 'Комплектът съдържа продукт, който не може да бъде поръчан.',
  bundle_insufficient_stock: 'Заявеното количество от комплекта не е налично.',
}

const UNKNOWN_ISSUE_MESSAGE = 'Този ред трябва да бъде прегледан преди поръчка.'

export function cartReadinessMessage(
  issue: CartIssue,
  readiness?: CartLineReadiness | null,
): string {
  if (issue.code === 'insufficient_stock' || issue.code === 'bundle_insufficient_stock') {
    const maximum = readiness?.stock.max_purchasable_quantity

    if (typeof maximum === 'number') {
      return `${ISSUE_MESSAGES[issue.code]} Можете да заявите до ${maximum} бр.`
    }
  }

  return ISSUE_MESSAGES[issue.code] ?? UNKNOWN_ISSUE_MESSAGE
}

export function cartReadinessMessages(readiness?: CartLineReadiness | null): string[] {
  return readiness?.issues.map(issue => cartReadinessMessage(issue, readiness)) ?? []
}
