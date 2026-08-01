import { defineStore } from 'pinia'
import type { CartResponse, ProductCard } from '~/types/api'
import type { CartApiError } from '~/utils/apiError'
import { normalizeApiError } from '~/utils/apiError'

export type CartRequestStatus = 'idle' | 'loading' | 'ready' | 'mutating' | 'error'

interface AcceptedCartMutation {
  cart: CartResponse
  previousCart: CartResponse | null
  operationId: string
  authorityVersion: number
  sequence: number
}

const CART_LEVEL_ERROR_CODES = [
  'invalid_cart_session',
  'invalid_cart_session_response',
  'cart_not_ready',
  'cart_price_changed',
  'cart_promotion_changed',
  'cart_mutation_conflict',
  'unauthenticated',
  'forbidden',
]

export const useCartStore = defineStore('cart', () => {
  const cart = ref<CartResponse | null>(null)
  const status = ref<CartRequestStatus>('idle')
  const error = ref<CartApiError | null>(null)
  const lastOperation = ref<string | null>(null)
  const pendingOperations = ref<Record<string, number>>({})
  const operationErrors = ref<Record<string, CartApiError>>({})
  const operationSequence = ref(0)
  const latestAcceptedSequence = ref(0)
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
  const hasConfirmedContent = computed(() => items.value.length > 0
    || bundleItems.value.length > 0
    || giftProducts.value.length > 0)
  const isConfirmedEmpty = computed(() => cart.value !== null && !hasConfirmedContent.value)
  const isUnresolved = computed(() => cart.value === null && status.value === 'idle')
  const isInitialLoading = computed(() => cart.value === null && status.value === 'loading')
  const isMutating = computed(() => Object.keys(pendingOperations.value).some(key => key !== 'sync'))
  const isReady = computed(() => cart.value !== null && status.value === 'ready')
  const hasError = computed(() => error.value !== null)
  const cartLevelError = computed(() => error.value && (
    lastOperation.value === 'sync'
    || CART_LEVEL_ERROR_CODES.includes(error.value.code)
  )
    ? error.value
    : null)

  function isOperationPending(key: string) {
    return Object.hasOwn(pendingOperations.value, key)
  }

  function errorFor(key: string) {
    return operationErrors.value[key] ?? null
  }

  function beginOperation(key: string) {
    if (isOperationPending(key)) {
      return null
    }

    operationSequence.value += 1
    const token = operationSequence.value
    pendingOperations.value = { ...pendingOperations.value, [key]: token }
    const nextErrors = { ...operationErrors.value }
    delete nextErrors[key]
    operationErrors.value = nextErrors
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

  function acceptConfirmedCart(nextCart: CartResponse, key: string, token: number) {
    cart.value = nextCart
    latestAcceptedSequence.value = token
    error.value = null
    const nextErrors = { ...operationErrors.value }
    delete nextErrors[key]
    operationErrors.value = nextErrors
    status.value = 'ready'
  }

  function failOperation(key: string, failure: unknown) {
    error.value = normalizeApiError(failure)
    operationErrors.value = { ...operationErrors.value, [key]: error.value }
    status.value = cart.value === null ? 'error' : 'ready'

    return error.value
  }

  function markUnresolvedForAuthTransition() {
    cart.value = null
    status.value = 'idle'
    error.value = null
    lastOperation.value = 'auth-transition'
    pendingOperations.value = {}
    operationErrors.value = {}
    authorityVersion.value += 1
  }

  function restoreAcceptedCartSession() {
    if (cart.value?.cart_session_id) {
      useCartSession().persist(cart.value.cart_session_id)
    }
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
        restoreAcceptedCartSession()
        return null
      }

      if (token < latestAcceptedSequence.value) {
        restoreAcceptedCartSession()
        return null
      }

      acceptConfirmedCart(response.data, key, token)

      return response.data
    } catch (failure) {
      if (
        authorityVersion.value !== expectedAuthorityVersion
        || token < latestAcceptedSequence.value
      ) {
        restoreAcceptedCartSession()
        return null
      }

      throw failOperation(key, failure)
    } finally {
      finishOperation(key, token)
    }
  }

  async function runMutation(
    key: string,
    request: () => Promise<{ data: CartResponse }>,
  ): Promise<AcceptedCartMutation | null> {
    const token = beginOperation(key)

    if (token === null) {
      return null
    }

    const expectedAuthorityVersion = authorityVersion.value
    const previousCart = cart.value
    const operationId = `${expectedAuthorityVersion}:${token}:${key}`

    try {
      const response = await request()

      if (
        authorityVersion.value !== expectedAuthorityVersion
        || token < latestAcceptedSequence.value
      ) {
        restoreAcceptedCartSession()
        return null
      }

      acceptConfirmedCart(response.data, key, token)

      return {
        cart: response.data,
        previousCart,
        operationId,
        authorityVersion: expectedAuthorityVersion,
        sequence: token,
      }
    } catch (failure) {
      if (
        authorityVersion.value !== expectedAuthorityVersion
        || token < latestAcceptedSequence.value
      ) {
        return null
      }

      throw failOperation(key, failure)
    } finally {
      finishOperation(key, token)
    }
  }

  function isAcceptedMutationCurrent(accepted: AcceptedCartMutation): boolean {
    return authorityVersion.value === accepted.authorityVersion
      && latestAcceptedSequence.value === accepted.sequence
  }

  async function add(product: ProductCard, quantity = 1) {
    const accepted = await runMutation(
      `add:${product.id}`,
      () => useCartApi().add(product.id, quantity),
    )

    if (!accepted) {
      return null
    }

    if (isAcceptedMutationCurrent(accepted)) {
      await useCartAnalytics().productAdded(
        accepted.operationId,
        accepted.previousCart,
        accepted.cart,
        product.id,
      )
    }

    return accepted.cart
  }

  async function update(itemId: number, quantity: number) {
    const accepted = await runMutation(
      `update:${itemId}`,
      () => useCartApi().update(itemId, quantity),
    )

    if (!accepted) {
      return null
    }

    if (isAcceptedMutationCurrent(accepted)) {
      await useCartAnalytics().productQuantityChanged(
        accepted.operationId,
        accepted.previousCart,
        accepted.cart,
        itemId,
      )
    }

    return accepted.cart
  }

  async function remove(itemId: number) {
    const accepted = await runMutation(
      `remove:${itemId}`,
      () => useCartApi().remove(itemId),
    )

    if (!accepted) {
      return null
    }

    if (isAcceptedMutationCurrent(accepted)) {
      await useCartAnalytics().productRemoved(
        accepted.operationId,
        accepted.previousCart,
        accepted.cart,
        itemId,
      )
    }

    return accepted.cart
  }

  async function clear() {
    const accepted = await runMutation('clear', () => useCartApi().clear())

    return accepted?.cart ?? null
  }

  async function addBundle(bundleId: number, quantity: number, selectedItems: Array<Record<string, unknown>> = []) {
    const accepted = await runMutation(
      `bundle:add:${bundleId}`,
      () => useCartApi().addBundle(bundleId, quantity, selectedItems),
    )

    if (!accepted) {
      return null
    }

    if (isAcceptedMutationCurrent(accepted)) {
      await useCartAnalytics().bundleAdded(
        accepted.operationId,
        accepted.previousCart,
        accepted.cart,
        bundleId,
      )
    }

    return accepted.cart
  }

  async function updateBundle(bundleItemId: number, quantity: number, selectedItems: Array<Record<string, unknown>> = []) {
    const accepted = await runMutation(
      `bundle:update:${bundleItemId}`,
      () => useCartApi().updateBundle(bundleItemId, quantity, selectedItems),
    )

    return accepted?.cart ?? null
  }

  async function removeBundle(bundleItemId: number) {
    const accepted = await runMutation(
      `bundle:remove:${bundleItemId}`,
      () => useCartApi().removeBundle(bundleItemId),
    )

    if (!accepted) {
      return null
    }

    if (isAcceptedMutationCurrent(accepted)) {
      await useCartAnalytics().bundleRemoved(
        accepted.operationId,
        accepted.previousCart,
        accepted.cart,
        bundleItemId,
      )
    }

    return accepted.cart
  }

  async function applyCoupon(code: string) {
    const accepted = await runMutation('coupon:apply', () => useCartApi().applyCoupon(code))

    return accepted?.cart ?? null
  }

  async function removeCoupon() {
    const accepted = await runMutation('coupon:remove', () => useCartApi().removeCoupon())

    return accepted?.cart ?? null
  }

  async function attachEmail(email: string) {
    const accepted = await runMutation('email', () => useCartApi().email(email))

    return accepted?.cart ?? null
  }

  async function recover(capability: string) {
    const accepted = await runMutation('recover', () => useCartApi().recover(capability))

    return accepted?.cart ?? null
  }

  async function acceptExternalMutation(
    key: string,
    request: () => Promise<{ data: CartResponse }>,
  ) {
    const accepted = await runMutation(key, request)

    return accepted?.cart ?? null
  }

  return {
    cart,
    status,
    error,
    lastOperation,
    pendingOperations,
    operationErrors,
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
    hasConfirmedContent,
    isConfirmedEmpty,
    isUnresolved,
    isInitialLoading,
    isMutating,
    isReady,
    hasError,
    cartLevelError,
    isOperationPending,
    errorFor,
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
