import type { CartBundleItem, CartItem, CartResponse } from '~/types/api'

export interface CartAnalyticsEvent {
  name: 'add_to_cart' | 'remove_from_cart' | 'begin_checkout'
  payload: Record<string, string | number>
}

function paidProductLine(cart: CartResponse | null, productId: number): CartItem | undefined {
  return cart?.items.find(item => item.product_id === productId && !item.is_gift)
}

function paidItem(cart: CartResponse | null, itemId: number): CartItem | undefined {
  return cart?.items.find(item => item.id === itemId && !item.is_gift)
}

function bundleItem(cart: CartResponse | null, bundleItemId: number): CartBundleItem | undefined {
  return cart?.bundle_items.find(item => item.id === bundleItemId)
}

function productPayload(
  line: CartItem,
  quantity: number,
  currency: string,
  unitPrice = Number(line.unit_price),
): Record<string, string | number> {
  return {
    product_id: line.product_id,
    sku: line.product.sku,
    quantity,
    unit_price: unitPrice,
    value: unitPrice * quantity,
    currency,
  }
}

function bundlePayload(
  line: CartBundleItem,
  quantity: number,
  currency: string,
  unitPrice = Number(line.unit_price),
): Record<string, string | number> {
  return {
    bundle_id: line.bundle_id,
    quantity,
    unit_price: unitPrice,
    value: unitPrice * quantity,
    currency,
  }
}

export function productAddedEvent(
  before: CartResponse | null,
  after: CartResponse,
  productId: number,
): CartAnalyticsEvent | null {
  const previous = paidProductLine(before, productId)
  const confirmed = paidProductLine(after, productId)
  const quantity = confirmed ? confirmed.quantity - (previous?.quantity ?? 0) : 0

  if (!confirmed || quantity <= 0) {
    return null
  }

  return {
    name: 'add_to_cart',
    payload: productPayload(confirmed, quantity, after.currency),
  }
}

export function productRemovedEvent(
  before: CartResponse | null,
  after: CartResponse,
  itemId: number,
): CartAnalyticsEvent | null {
  const removed = paidItem(before, itemId)

  if (!removed || paidItem(after, itemId)) {
    return null
  }

  return {
    name: 'remove_from_cart',
    payload: productPayload(removed, removed.quantity, after.currency),
  }
}

export function productQuantityEvent(
  before: CartResponse | null,
  after: CartResponse,
  itemId: number,
): CartAnalyticsEvent | null {
  const previous = paidItem(before, itemId)
  const confirmed = paidItem(after, itemId)

  if (!previous || !confirmed || previous.quantity === confirmed.quantity) {
    return null
  }

  if (confirmed.quantity > previous.quantity) {
    const quantity = confirmed.quantity - previous.quantity

    return {
      name: 'add_to_cart',
      payload: productPayload(confirmed, quantity, after.currency),
    }
  }

  const quantity = previous.quantity - confirmed.quantity

  return {
    name: 'remove_from_cart',
    payload: productPayload(previous, quantity, after.currency),
  }
}

export function bundleAddedEvent(
  before: CartResponse | null,
  after: CartResponse,
  bundleId: number,
): CartAnalyticsEvent | null {
  const previous = before?.bundle_items.find(item => item.bundle_id === bundleId)
  const confirmed = after.bundle_items.find(item => item.bundle_id === bundleId)
  const quantity = confirmed ? confirmed.quantity - (previous?.quantity ?? 0) : 0

  if (!confirmed || quantity <= 0) {
    return null
  }

  return {
    name: 'add_to_cart',
    payload: bundlePayload(confirmed, quantity, after.currency),
  }
}

export function bundleRemovedEvent(
  before: CartResponse | null,
  after: CartResponse,
  bundleItemId: number,
): CartAnalyticsEvent | null {
  const removed = bundleItem(before, bundleItemId)

  if (!removed || bundleItem(after, bundleItemId)) {
    return null
  }

  return {
    name: 'remove_from_cart',
    payload: bundlePayload(removed, removed.quantity, after.currency),
  }
}

export function beginCheckoutEvent(cart: CartResponse): CartAnalyticsEvent | null {
  if (!cart.readiness?.can_checkout) {
    return null
  }

  return {
    name: 'begin_checkout',
    payload: {
      value: Number(cart.subtotal),
      items_count: cart.items_count,
      currency: cart.currency,
    },
  }
}
