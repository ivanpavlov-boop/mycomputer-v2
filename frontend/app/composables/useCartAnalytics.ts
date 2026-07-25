import type { CartResponse } from '~/types/api'
import {
  beginCheckoutEvent,
  bundleAddedEvent,
  bundleRemovedEvent,
  productAddedEvent,
  productQuantityEvent,
  productRemovedEvent,
  type CartAnalyticsEvent,
} from '~/utils/cartAnalytics'

const MAX_REMEMBERED_OPERATIONS = 200

export function useCartAnalytics() {
  const analytics = useAnalytics()
  const emittedOperations = useState<string[]>('cart-analytics-emitted-operations', () => [])
  const operationSequence = useState<number>('cart-analytics-operation-sequence', () => 0)

  function createOperationId(scope: string): string {
    operationSequence.value += 1

    return `${scope}:${operationSequence.value}`
  }

  async function emitOnce(operationId: string, event: CartAnalyticsEvent | null): Promise<boolean> {
    if (!event || emittedOperations.value.includes(operationId)) {
      return false
    }

    emittedOperations.value = [...emittedOperations.value, operationId].slice(-MAX_REMEMBERED_OPERATIONS)

    if (event.name === 'add_to_cart') {
      await analytics.addToCart(event.payload)
    } else if (event.name === 'remove_from_cart') {
      await analytics.removeFromCart(event.payload)
    } else {
      await analytics.beginCheckout(event.payload)
    }

    return true
  }

  return {
    createOperationId,
    productAdded: (
      operationId: string,
      before: CartResponse | null,
      after: CartResponse,
      productId: number,
    ) => emitOnce(operationId, productAddedEvent(before, after, productId)),
    productRemoved: (
      operationId: string,
      before: CartResponse | null,
      after: CartResponse,
      itemId: number,
    ) => emitOnce(operationId, productRemovedEvent(before, after, itemId)),
    productQuantityChanged: (
      operationId: string,
      before: CartResponse | null,
      after: CartResponse,
      itemId: number,
    ) => emitOnce(operationId, productQuantityEvent(before, after, itemId)),
    bundleAdded: (
      operationId: string,
      before: CartResponse | null,
      after: CartResponse,
      bundleId: number,
    ) => emitOnce(operationId, bundleAddedEvent(before, after, bundleId)),
    bundleRemoved: (
      operationId: string,
      before: CartResponse | null,
      after: CartResponse,
      bundleItemId: number,
    ) => emitOnce(operationId, bundleRemovedEvent(before, after, bundleItemId)),
    beginCheckout: (
      operationId: string,
      cart: CartResponse,
    ) => emitOnce(operationId, beginCheckoutEvent(cart)),
  }
}
