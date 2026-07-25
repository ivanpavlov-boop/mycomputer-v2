import { defineStore } from 'pinia'
import type { CartResponse, ProductCard } from '~/types/api'
import type { CartApiError } from '~/utils/apiError'
import { normalizeApiError } from '~/utils/apiError'

export type CartRequestStatus = 'idle' | 'loading' | 'ready' | 'mutating' | 'error'

export const useCartStore = defineStore('cart', () => {
  const cart = ref<CartResponse | null>(null)
  const status = ref<CartRequestStatus>('idle')
  const error = ref<CartApiError | null>(null)
  const lastOperation = ref<string | null>(null)
  const pendingOperations = ref<Record<string, number>>({})
  const operationSequence = ref(0)
  const authorityVersion = ref(0)

  const items = computed(() => cart.value?.items ?? [])
  const paidItems = computed(() => items.value.filter(item => !item.is_gift))
  const giftItems = computed(() => items.value.filter(item => item.is_gift))
  const bundleItems = computed(() => cart.value?.bundle_items ?? [])
  const giftProducts = computed(() => cart.value?.gift_products ?? [])
  const couponCode = computed(() => cart.value?.coupon_code ?? null)
  const readiness = computed(() => cart.value?.readiness ?? null)
  const count = computed(() => cart.value?.items_count ?? 0)
  const subtotal = computed(() => Number(cart.value?.subtotal ?? 0))
  const currency = computed(() => cart.value?.currency ?? 'EUR')
  const canCheckout = computed(() => readiness.value?.can_checkout === true)
  const isInitialLoading = computed(() => cart.value === null && status.value === 'loading')
  const isMutating = computed(() => Object.keys(pendingOperations.value).some(key => key !== 'sync'))
  const isReady = computed(() => cart.value !== null && status.value === 'ready')
  const hasError = computed(() => error.value !== null)

  function isOperationPending(key: string) {
    return Object.hasOwn(pendingOperations.value, key)
  }

  function beginOperation(key: string) {
    if (isOperationPending(key)) {
      return null
    }

    operationSequence.value += 1
    const token = operationSequence.value
    pendingOperations.value = { ...pendingOperations.value, [key]: token }
    lastOperation.value = key
    error.value = null
    status.value = key === 'sync' && cart.value === null ? 'loading' : 'mutating'

    return token
  }

  function finishOperation(key: string, token: number) {
    if (pendingOperations.value[key] !== token) {
      return
    }

    const nextPending = { ...pendingOperations.value }
    delete nextPending[key]
    pendingOperations.value = nextPending

    if (Object.keys(nextPending).some(operation => operation !== 'sync')) {
      status.value = 'mutating'
    } else {
      status.value = cart.value === null ? (error.value ? 'error' : 'idle') : 'ready'
    }
  }

  function acceptConfirmedCart(nextCart: CartResponse) {
    cart.value = nextCart
    error.value = null
    status.value = 'ready'
  }

  function failOperation(failure: unknown) {
    error.value = normalizeApiError(failure)
    status.value = cart.value === null ? 'error' : 'ready'

    return error.value
  }

  function markUnresolvedForAuthTransition() {
    cart.value = null
    status.value = 'idle'
    error.value = null
    lastOperation.value = 'auth-transition'
    pendingOperations.value = {}
    authorityVersion.value += 1
  }

  async function sync() {
    const key = 'sync'
    const token = beginOperation(key)

    if (token === null) {
      return cart.value
    }

    const expectedAuthorityVersion = authorityVersion.value

    try {
      const response = await useCartApi().get()

      if (authorityVersion.value !== expectedAuthorityVersion) {
        return null
      }

      acceptConfirmedCart(response.data)

      return response.data
    } catch (failure) {
      if (authorityVersion.value !== expectedAuthorityVersion) {
        return null
      }

      throw failOperation(failure)
    } finally {
      finishOperation(key, token)
    }
  }

  async function runMutation(
    key: string,
    request: () => Promise<{ data: CartResponse }>,
  ): Promise<CartResponse | null> {
    const token = beginOperation(key)

    if (token === null) {
      return null
    }

    const expectedAuthorityVersion = authorityVersion.value

    try {
      const response = await request()

      if (authorityVersion.value !== expectedAuthorityVersion) {
        return null
      }

      acceptConfirmedCart(response.data)

      return response.data
    } catch (failure) {
      if (authorityVersion.value !== expectedAuthorityVersion) {
        return null
      }

      throw failOperation(failure)
    } finally {
      finishOperation(key, token)
    }
  }

  async function add(product: ProductCard, quantity = 1) {
    const previous = cart.value?.items.find(item => item.product_id === product.id && !item.is_gift)
    const confirmed = await runMutation(
      `add:${product.id}`,
      () => useCartApi().add(product.id, quantity),
    )

    if (confirmed === null) {
      return null
    }

    const item = confirmed.items.find(line => line.product_id === product.id && !line.is_gift)
    const confirmedQuantity = item ? Math.max(0, item.quantity - (previous?.quantity ?? 0)) : 0

    if (item && confirmedQuantity > 0) {
      await useAnalytics().addToCart({
        product_id: item.product_id,
        sku: item.product.sku,
        quantity: confirmedQuantity,
        value: Number(item.unit_price) * confirmedQuantity,
        currency: confirmed.currency,
      })
    }

    return confirmed
  }

  async function update(itemId: number, quantity: number) {
    return runMutation(
      `update:${itemId}`,
      () => useCartApi().update(itemId, quantity),
    )
  }

  async function remove(itemId: number) {
    const previous = cart.value?.items.find(item => item.id === itemId)
    const confirmed = await runMutation(
      `remove:${itemId}`,
      () => useCartApi().remove(itemId),
    )

    if (confirmed !== null && previous) {
      await useAnalytics().removeFromCart({
        product_id: previous.product_id,
        cart_item_id: previous.id,
        sku: previous.product.sku,
        quantity: previous.quantity,
        value: Number(previous.unit_price) * previous.quantity,
        currency: confirmed.currency,
      })
    }

    return confirmed
  }

  async function clear() {
    return runMutation('clear', () => useCartApi().clear())
  }

  async function addBundle(bundleId: number, quantity: number, selectedItems: Array<Record<string, unknown>> = []) {
    const previous = cart.value?.bundle_items.find(item => item.bundle_id === bundleId)
    const confirmed = await runMutation(
      `bundle:add:${bundleId}`,
      () => useCartApi().addBundle(bundleId, quantity, selectedItems),
    )

    if (confirmed === null) {
      return null
    }

    const item = confirmed.bundle_items.find(line => line.bundle_id === bundleId)
    const confirmedQuantity = item ? Math.max(0, item.quantity - (previous?.quantity ?? 0)) : 0

    if (item && confirmedQuantity > 0) {
      await useAnalytics().addToCart({
        bundle_id: item.bundle_id,
        quantity: confirmedQuantity,
        value: Number(item.unit_price) * confirmedQuantity,
        currency: confirmed.currency,
      })
    }

    return confirmed
  }

  async function updateBundle(bundleItemId: number, quantity: number, selectedItems: Array<Record<string, unknown>> = []) {
    return runMutation(
      `bundle:update:${bundleItemId}`,
      () => useCartApi().updateBundle(bundleItemId, quantity, selectedItems),
    )
  }

  async function removeBundle(bundleItemId: number) {
    const previous = cart.value?.bundle_items.find(item => item.id === bundleItemId)
    const confirmed = await runMutation(
      `bundle:remove:${bundleItemId}`,
      () => useCartApi().removeBundle(bundleItemId),
    )

    if (confirmed !== null && previous) {
      await useAnalytics().removeFromCart({
        bundle_id: previous.bundle_id,
        quantity: previous.quantity,
        value: Number(previous.unit_price) * previous.quantity,
        currency: confirmed.currency,
      })
    }

    return confirmed
  }

  async function applyCoupon(code: string) {
    return runMutation('coupon', () => useCartApi().applyCoupon(code))
  }

  async function removeCoupon() {
    return runMutation('coupon', () => useCartApi().removeCoupon())
  }

  async function attachEmail(email: string) {
    return runMutation('email', () => useCartApi().email(email))
  }

  async function recover(token: string) {
    return runMutation('recover', () => useCartApi().recover(token))
  }

  async function acceptExternalMutation(
    key: string,
    request: () => Promise<{ data: CartResponse }>,
  ) {
    return runMutation(key, request)
  }

  return {
    cart,
    status,
    error,
    lastOperation,
    pendingOperations,
    items,
    paidItems,
    giftItems,
    bundleItems,
    giftProducts,
    couponCode,
    readiness,
    count,
    subtotal,
    currency,
    canCheckout,
    isInitialLoading,
    isMutating,
    isReady,
    hasError,
    isOperationPending,
    markUnresolvedForAuthTransition,
    sync,
    add,
    update,
    remove,
    clear,
    addBundle,
    updateBundle,
    removeBundle,
    applyCoupon,
    removeCoupon,
    attachEmail,
    recover,
    acceptExternalMutation,
  }
})
