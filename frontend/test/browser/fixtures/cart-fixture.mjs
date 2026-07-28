import { createHash, createHmac, randomBytes } from 'node:crypto'

export const FIXTURE_ORIGIN = 'http://127.0.0.1:3000'
export const FIXTURE_API_URL = 'http://127.0.0.1:4010'

export const FIXTURE_CART_KEYS = [
  'cart_session_id',
  'status',
  'currency',
  'coupon_code',
  'items',
  'bundle_items',
  'items_count',
  'subtotal',
  'applied_promotions',
  'promotion_discount_total',
  'shipping_discount',
  'gift_products',
  'readiness',
  'expires_at',
]

export const FIXTURE_PRODUCT = Object.freeze({
  id: 101,
  sku: 'TEST-LAPTOP-101',
  ean: '3800000000101',
  name: 'Тестов лаптоп',
  slug: 'testov-laptop',
  short_description: 'Локален продукт за браузърни тестове.',
  currency: 'EUR',
  price: '999.90',
  promo_price: null,
  quantity: 12,
  stock_status: 'in_stock',
  availability: {
    code: 'in_stock',
    name: 'В наличност',
    allow_purchase: true,
    show_stock_quantity: true,
  },
  brand: { id: 11, name: 'Fixture Brand', slug: 'fixture-brand' },
  category: { id: 21, name: 'Лаптопи', slug: 'laptops' },
  primary_image: null,
})

export const FIXTURE_BUNDLE = Object.freeze({
  id: 201,
  name: 'Тестов комплект',
  slug: 'testov-komplekt',
  type: 'fixed',
  pricing_type: 'fixed',
  short_description: 'Локален комплект за браузърни тестове.',
  description: 'Използва се само от детерминираната локална fixture услуга.',
  image_path: null,
  original_price: '1099.90',
  price: '899.90',
  savings: '200.00',
  items: [
    {
      id: 301,
      component_group: 'base',
      is_required: true,
      quantity: 1,
      min_quantity: 1,
      max_quantity: 1,
      product: FIXTURE_PRODUCT,
    },
  ],
  options: [],
  seo: {
    meta_title: 'Тестов комплект',
    meta_description: 'Локален комплект за браузърни тестове.',
  },
})

const AUTH_SESSIONS = {
  'fixture-token-user-a': 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
  'fixture-token-user-b': 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
}

function clone(value) {
  return structuredClone(value)
}

function canonicalize(value) {
  if (Array.isArray(value)) {
    return value.map(canonicalize)
  }

  if (typeof value !== 'object' || value === null) {
    return value
  }

  return Object.fromEntries(
    Object.keys(value)
      .sort()
      .map(key => [key, canonicalize(value[key])]),
  )
}

function lineReadiness(issueCode = null) {
  const issues = issueCode
    ? [{ code: issueCode, message: 'Fixture readiness issue.' }]
    : []

  return {
    is_eligible: issueCode === null,
    can_checkout: issueCode === null,
    issues,
    stock: {
      tracked: true,
      requested_quantity: 1,
      available_quantity: issueCode === 'insufficient_stock' ? 0 : 12,
      max_purchasable_quantity: issueCode === 'insufficient_stock' ? 0 : 12,
      is_sufficient: issueCode !== 'insufficient_stock',
    },
  }
}

function paidLine(quantity = 1, issueCode = null) {
  return {
    id: 501,
    product_id: FIXTURE_PRODUCT.id,
    quantity,
    is_gift: false,
    promotion_id: null,
    unit_price: FIXTURE_PRODUCT.price,
    total_price: (Number(FIXTURE_PRODUCT.price) * quantity).toFixed(2),
    product: clone(FIXTURE_PRODUCT),
    readiness: lineReadiness(issueCode),
  }
}

function giftLine() {
  const product = {
    ...clone(FIXTURE_PRODUCT),
    id: 102,
    sku: 'TEST-GIFT-102',
    name: 'Тестов подарък',
    slug: 'testov-podarak',
    price: '0.00',
  }

  return {
    id: 502,
    product_id: product.id,
    quantity: 1,
    is_gift: true,
    promotion_id: 701,
    unit_price: '0.00',
    total_price: '0.00',
    product,
    readiness: lineReadiness(),
  }
}

function bundleLine(issueCode = null) {
  return {
    id: 601,
    bundle_id: FIXTURE_BUNDLE.id,
    bundle_name: FIXTURE_BUNDLE.name,
    selected_items: [
      {
        component_group: 'base',
        product_id: FIXTURE_PRODUCT.id,
        name: FIXTURE_PRODUCT.name,
        quantity: 1,
      },
    ],
    quantity: 1,
    unit_price: FIXTURE_BUNDLE.price,
    total_price: FIXTURE_BUNDLE.price,
    original_price: FIXTURE_BUNDLE.original_price,
    savings: FIXTURE_BUNDLE.savings,
    readiness: lineReadiness(issueCode),
  }
}

export function makeCartFixture(sessionId, preset = 'empty', issueCode = null) {
  const items = ['product', 'gift', 'blocked'].includes(preset)
    ? [paidLine(1, preset === 'blocked' ? (issueCode || 'product_inactive') : issueCode)]
    : []
  const gifts = preset === 'gift' ? [giftLine()] : []
  const bundles = preset === 'bundle' ? [bundleLine(issueCode)] : []
  const allItems = [...items, ...gifts]
  const canCheckout = (allItems.length > 0 || bundles.length > 0)
    && allItems.every(item => item.readiness?.can_checkout !== false)
    && bundles.every(item => item.readiness?.can_checkout !== false)

  return recalculateCart({
    id: 1,
    cart_session_id: sessionId,
    status: 'active',
    currency: 'EUR',
    coupon_code: null,
    items: allItems,
    bundle_items: bundles,
    items_count: 0,
    subtotal: '0.00',
    applied_promotions: preset === 'gift'
      ? [{
          id: 701,
          name: 'Тестова промоция',
          code: null,
          type: 'gift',
          discount: '0.00',
          shipping_discount: '0.00',
        }]
      : [],
    promotion_discount_total: '0.00',
    shipping_discount: '0.00',
    gift_products: preset === 'gift'
      ? [{ product_id: 102, quantity: 1, promotion_id: 701 }]
      : [],
    readiness: {
      can_checkout: canCheckout,
      issues_count: canCheckout ? 0 : (allItems.length || bundles.length ? 1 : 0),
      has_product_issues: !canCheckout && (allItems.length > 0 || bundles.length > 0),
      has_stock_issues: issueCode === 'insufficient_stock',
    },
    expires_at: '2030-01-01T00:00:00.000000Z',
  })
}

export function recalculateCart(cart) {
  for (const item of cart.items) {
    item.total_price = (Number(item.unit_price) * item.quantity).toFixed(2)
    if (item.readiness?.stock) {
      item.readiness.stock.requested_quantity = item.quantity
    }
  }

  for (const item of cart.bundle_items) {
    item.total_price = (Number(item.unit_price) * item.quantity).toFixed(2)
  }

  const paidSubtotal = cart.items
    .filter(item => !item.is_gift)
    .reduce((sum, item) => sum + Number(item.total_price), 0)
  const bundleSubtotal = cart.bundle_items
    .reduce((sum, item) => sum + Number(item.total_price), 0)

  cart.items_count = cart.items.reduce((sum, item) => sum + item.quantity, 0)
    + cart.bundle_items.reduce((sum, item) => sum + item.quantity, 0)
  cart.subtotal = (paidSubtotal + bundleSubtotal - Number(cart.promotion_discount_total)).toFixed(2)

  return cart
}

export function safeError(code = 'request_failed', status = 422, details = null) {
  return {
    status,
    body: {
      message: 'Fixture request failed.',
      error: {
        code,
        message: 'Fixture request failed.',
        details,
      },
    },
  }
}

function sensitiveKey(key) {
  return /(authorization|bearer|password|token|session|email|supplier|purchase_price|margin|secret)/i.test(key)
}

export function sanitizeAnalyticsEvent(input) {
  const event = typeof input === 'object' && input !== null ? input : {}
  const payload = typeof event.payload === 'object' && event.payload !== null ? event.payload : {}
  const safePayload = Object.fromEntries(
    Object.entries(payload).filter(([key, value]) => {
      return !sensitiveKey(key)
        && (value === null || ['string', 'number', 'boolean'].includes(typeof value))
    }),
  )

  return {
    event_name: typeof event.event_name === 'string' ? event.event_name : 'unknown',
    source: typeof event.source === 'string' ? event.source : 'internal',
    payload: safePayload,
  }
}

function userForToken(token) {
  const userId = token === 'fixture-token-user-b' ? 2 : 1

  return {
    id: userId,
    first_name: userId === 1 ? 'Тест' : 'Втори',
    last_name: 'Потребител',
    name: userId === 1 ? 'Тест Потребител' : 'Втори Потребител',
    email: userId === 1 ? 'customer@example.test' : 'customer-b@example.test',
    roles: ['customer'],
    profile: null,
  }
}

function defaultScenario() {
  return {
    preset: 'empty',
    issue_code: null,
    fail_next_get: false,
    fail_next_mutation: false,
    mutation_error_code: 'request_failed',
    mutation_delay_ms: 0,
    get_delay_ms: 0,
    rotate_next_get: null,
    next_checkout_error: 'cart_not_ready',
    lose_next_checkout_response: false,
    expire_confirmation: false,
    leasing_enabled: false,
    card_enabled: false,
    card_payment_state: 'pending',
    card_retry_state: 'authorized',
    card_redirect_url: 'https://payments.example.test/continue',
    lose_next_payment_attempt_response: false,
  }
}

export function createFixtureState() {
  let sessionSequence = 0
  const sessions = new Map()
  const confirmations = new Map()
  const paymentRetryCapabilities = new Map()
  const paymentAttemptsByKey = new Map()
  const checkoutByKey = new Map()
  const checkoutByCart = new Map()
  const requests = []
  const analytics = []
  let scenario = defaultScenario()
  let ordersCreated = 0
  let paymentAttempts = 0
  let paymentTransactions = 0
  let paymentRetryAttempts = 0
  let providerInvocations = 0
  let notificationsDispatched = 0
  let leasingApplicationsCreated = 0

  function nextSessionId() {
    sessionSequence += 1

    return `00000000-0000-4000-8000-${String(sessionSequence).padStart(12, '0')}`
  }

  function reset() {
    sessionSequence = 0
    sessions.clear()
    confirmations.clear()
    paymentRetryCapabilities.clear()
    paymentAttemptsByKey.clear()
    checkoutByKey.clear()
    checkoutByCart.clear()
    requests.length = 0
    analytics.length = 0
    scenario = defaultScenario()
    ordersCreated = 0
    paymentAttempts = 0
    paymentTransactions = 0
    paymentRetryAttempts = 0
    providerInvocations = 0
    notificationsDispatched = 0
    leasingApplicationsCreated = 0
  }

  function configure(input = {}) {
    scenario = { ...scenario, ...input }

    if (input.seed_session_id) {
      const cart = makeCartFixture(
        input.seed_session_id,
        input.preset || 'empty',
        input.issue_code || null,
      )
      sessions.set(input.seed_session_id, {
        cart,
        owner: input.owner || null,
      })
    }

    return snapshot()
  }

  function createSession(preset = scenario.preset, issueCode = scenario.issue_code, owner = null) {
    const sessionId = nextSessionId()
    const entry = {
      cart: makeCartFixture(sessionId, preset, issueCode),
      owner,
    }
    entry.cart.id = sessionSequence
    sessions.set(sessionId, entry)

    return entry
  }

  function rotate(entry, oldSessionId, reason) {
    const nextSession = nextSessionId()
    const next = {
      cart: clone(entry.cart),
      owner: entry.owner,
    }
    next.cart.cart_session_id = nextSession
    next.cart.id = entry.cart.id + 100
    sessions.set(nextSession, next)
    sessions.delete(oldSessionId)

    scenario.rotate_next_get = null

    return { entry: next, reason }
  }

  function resolveSession(sentSession, bearerToken, allowRotation = true) {
    if (bearerToken && AUTH_SESSIONS[bearerToken]) {
      const canonical = AUTH_SESSIONS[bearerToken]
      let authenticated = sessions.get(canonical)

      if (!authenticated) {
        const guest = sentSession ? sessions.get(sentSession) : null
        authenticated = {
          cart: guest && !guest.owner
            ? clone(guest.cart)
            : makeCartFixture(canonical, 'empty'),
          owner: bearerToken,
        }
        authenticated.cart.cart_session_id = canonical
        sessions.set(canonical, authenticated)
      }

      return { entry: authenticated, sessionId: canonical, rotationReason: 'authenticated' }
    }

    let entry = sentSession ? sessions.get(sentSession) : null

    if (entry?.owner) {
      entry = createSession('empty')

      return {
        entry,
        sessionId: entry.cart.cart_session_id,
        rotationReason: 'logout',
      }
    }

    if (!entry) {
      entry = createSession()

      return {
        entry,
        sessionId: entry.cart.cart_session_id,
        rotationReason: sentSession ? 'unknown' : null,
      }
    }

    if (allowRotation && scenario.rotate_next_get) {
      const rotated = rotate(entry, sentSession, scenario.rotate_next_get)

      return {
        entry: rotated.entry,
        sessionId: rotated.entry.cart.cart_session_id,
        rotationReason: rotated.reason,
      }
    }

    return { entry, sessionId: sentSession, rotationReason: null }
  }

  function recordRequest(request) {
    const validIdempotencyKey = typeof request.idempotencyKey === 'string'
      && /^[A-Za-z0-9_-]{43}$/.test(request.idempotencyKey)

    requests.push({
      method: request.method,
      path: request.path,
      cart_session: request.cartSession || null,
      authorization_present: Boolean(request.bearerToken),
      origin: request.origin || null,
      idempotency_key_present: typeof request.idempotencyKey === 'string',
      idempotency_key_valid: validIdempotencyKey,
      idempotency_identity: validIdempotencyKey
        ? createHash('sha256').update(request.idempotencyKey).digest('hex')
        : null,
    })
  }

  function inspectCheckout(key, cartSession, payload) {
    if (typeof key !== 'string' || !/^[A-Za-z0-9_-]{43}$/.test(key)) {
      return { error: 'checkout_idempotency_key_invalid' }
    }

    const keyHash = createHash('sha256').update(key).digest('hex')
    const requestHash = createHmac('sha256', 'fixture-checkout-fingerprint-v1')
      .update(JSON.stringify(canonicalize(payload)))
      .digest('hex')
    const byKey = checkoutByKey.get(keyHash)
    const byCart = checkoutByCart.get(cartSession)

    if (byKey && byKey.cartSession !== cartSession) {
      return { error: 'checkout_idempotency_conflict' }
    }

    const existing = byKey || byCart

    if (existing) {
      if (existing.requestHash !== requestHash) {
        return {
          error: byKey
            ? 'checkout_idempotency_conflict'
            : 'checkout_already_completed',
        }
      }

      return { replay: existing }
    }

    return {
      pending: {
        keyHash,
        requestHash,
        cartSession,
      },
    }
  }

  function completeCheckout(pending, checkout, options = {}) {
    const completed = {
      ...pending,
      checkout: clone(checkout),
    }
    checkoutByKey.set(pending.keyHash, completed)
    checkoutByCart.set(pending.cartSession, completed)
    ordersCreated += 1
    paymentAttempts += 1
    paymentTransactions += 1
    notificationsDispatched += 1
    if (options.providerInvocation === true) {
      providerInvocations += 1
    }
    if (options.leasingApplication === true) {
      leasingApplicationsCreated += 1
    }

    return completed
  }

  function recordAnalytics(event) {
    analytics.push(sanitizeAnalyticsEvent(event))
  }

  function issueConfirmation(data) {
    const token = randomBytes(32).toString('base64url')
    const tokenHash = createHash('sha256').update(token).digest('hex')

    confirmations.set(tokenHash, {
      data: clone(data),
      expiresAt: Date.now() + (120 * 60 * 1000),
    })

    return token
  }

  function resolveConfirmation(token) {
    if (typeof token !== 'string' || !/^[A-Za-z0-9_-]{43}$/.test(token)) {
      return null
    }

    const tokenHash = createHash('sha256').update(token).digest('hex')
    const confirmation = confirmations.get(tokenHash)

    if (!confirmation || scenario.expire_confirmation || confirmation.expiresAt <= Date.now()) {
      return null
    }

    return clone(confirmation.data)
  }

  function issuePaymentRetry(orderNumber) {
    const token = randomBytes(32).toString('base64url')
    const tokenHash = createHash('sha256').update(token).digest('hex')

    paymentRetryCapabilities.set(tokenHash, {
      orderNumber,
      expiresAt: Date.now() + (60 * 60 * 1000),
    })

    return token
  }

  function resolvePaymentRetry(token) {
    if (typeof token !== 'string' || !/^[A-Za-z0-9_-]{43}$/.test(token)) {
      return null
    }

    const tokenHash = createHash('sha256').update(token).digest('hex')
    const capability = paymentRetryCapabilities.get(tokenHash)

    if (!capability || capability.expiresAt <= Date.now()) {
      return null
    }

    return clone(capability)
  }

  function inspectPaymentAttempt(key, orderNumber) {
    if (typeof key !== 'string' || !/^[A-Za-z0-9_-]{43}$/.test(key)) {
      return { error: 'payment_attempt_idempotency_key_invalid' }
    }

    const keyHash = createHash('sha256').update(key).digest('hex')
    const existing = paymentAttemptsByKey.get(keyHash)

    if (existing) {
      return existing.orderNumber === orderNumber
        ? { replay: existing }
        : { error: 'payment_attempt_idempotency_conflict' }
    }

    return { pending: { keyHash, orderNumber } }
  }

  function completePaymentAttempt(pending, result) {
    const completed = {
      ...pending,
      result: clone(result),
    }

    paymentAttemptsByKey.set(pending.keyHash, completed)
    paymentRetryAttempts += 1
    providerInvocations += 1

    return completed
  }

  function snapshot() {
    return {
      scenario: clone(scenario),
      sessions: [...sessions.values()].map(({ cart, owner }) => ({
        cart: clone(cart),
        owner: owner ? '[authenticated]' : null,
      })),
      requests: clone(requests),
      analytics: clone(analytics),
      orders_created: ordersCreated,
      payment_attempts: paymentAttempts,
      payment_transactions: paymentTransactions,
      payment_retry_attempts: paymentRetryAttempts,
      provider_invocations: providerInvocations,
      notifications_dispatched: notificationsDispatched,
      leasing_applications_created: leasingApplicationsCreated,
      confirmation_capabilities: confirmations.size,
      payment_retry_capabilities: paymentRetryCapabilities.size,
      checkout_identities: [...checkoutByKey.values()].map(record => ({
        identity: record.keyHash,
        completed: true,
      })),
    }
  }

  return {
    sessions,
    requests,
    analytics,
    get scenario() {
      return scenario
    },
    reset,
    configure,
    createSession,
    resolveSession,
    recordRequest,
    recordAnalytics,
    inspectCheckout,
    completeCheckout,
    issueConfirmation,
    resolveConfirmation,
    issuePaymentRetry,
    resolvePaymentRetry,
    inspectPaymentAttempt,
    completePaymentAttempt,
    snapshot,
    incrementOrders() {
      ordersCreated += 1
    },
    incrementPayments() {
      paymentAttempts += 1
    },
  }
}
