import { createServer } from 'node:http'
import { fileURLToPath } from 'node:url'
import {
  FIXTURE_API_URL,
  FIXTURE_BUNDLE,
  FIXTURE_CATEGORY,
  FIXTURE_ORIGIN,
  FIXTURE_PRODUCT,
  createFixtureState,
  makeCartFixture,
  recalculateCart,
  safeError,
} from './cart-fixture.mjs'

const state = createFixtureState()
const port = Number(new URL(FIXTURE_API_URL).port)

function corsHeaders(origin) {
  return {
    'Access-Control-Allow-Origin': origin === FIXTURE_ORIGIN ? origin : FIXTURE_ORIGIN,
    'Access-Control-Allow-Headers': 'Authorization, Content-Type, Idempotency-Key, X-Cart-Session, X-Compare-Session, X-Locale, X-Marketing-Session',
    'Access-Control-Allow-Methods': 'GET, POST, PATCH, DELETE, OPTIONS',
    'Access-Control-Expose-Headers': 'Content-Type',
    'Access-Control-Allow-Credentials': 'true',
    Vary: 'Origin',
  }
}

function send(response, status, body, origin = null, headers = {}) {
  response.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    ...corsHeaders(origin),
    ...headers,
  })
  response.end(JSON.stringify(body))
}

async function bodyOf(request) {
  const chunks = []

  for await (const chunk of request) {
    chunks.push(chunk)
  }

  if (!chunks.length) {
    return {}
  }

  try {
    return JSON.parse(Buffer.concat(chunks).toString('utf8'))
  } catch {
    return {}
  }
}

function bearerToken(request) {
  const header = request.headers.authorization

  return typeof header === 'string' && header.startsWith('Bearer ')
    ? header.slice('Bearer '.length)
    : null
}

function cookieValue(request, name) {
  const header = request.headers.cookie

  if (typeof header !== 'string') {
    return null
  }

  for (const part of header.split(';')) {
    const separator = part.indexOf('=')
    const key = separator >= 0 ? part.slice(0, separator).trim() : ''

    if (key === name) {
      return decodeURIComponent(part.slice(separator + 1).trim())
    }
  }

  return null
}

function maskEmail(value) {
  const email = typeof value === 'string' ? value.trim() : ''
  const separator = email.lastIndexOf('@')

  if (separator <= 0 || separator === email.length - 1) {
    return '***'
  }

  return `${email.charAt(0)}***@${email.slice(separator + 1)}`
}

function wait(milliseconds) {
  return milliseconds > 0
    ? new Promise(resolve => setTimeout(resolve, milliseconds))
    : Promise.resolve()
}

function mutationFailure(response, origin) {
  if (!state.scenario.fail_next_mutation) {
    return false
  }

  state.configure({ fail_next_mutation: false })
  const failure = safeError(state.scenario.mutation_error_code, 409)
  send(response, failure.status, failure.body, origin)

  return true
}

function currentCart(request, allowRotation = false) {
  return state.resolveSession(
    request.headers['x-cart-session'] || null,
    bearerToken(request),
    allowRotation,
  )
}

function routePattern(path, pattern) {
  const match = path.match(pattern)

  return match ? match.slice(1) : null
}

const leasingOptions = {
  term_months: [6, 12, 18, 24, 36, 48],
  contact_methods: [
    { value: 'phone', label: 'Телефон' },
    { value: 'email', label: 'E-mail' },
    { value: 'either', label: 'Телефон или e-mail' },
  ],
  contact_time_slots: [
    { value: 'anytime', label: 'Без предпочитание' },
    { value: 'morning', label: 'Сутрин' },
    { value: 'afternoon', label: 'Следобед' },
    { value: 'evening', label: 'Вечер' },
  ],
  currency: 'EUR',
}

function validateLeasingApplication(input, grandTotal) {
  const application = input?.leasing_application
  const errors = {}

  if (!application || typeof application !== 'object' || Array.isArray(application)) {
    return { leasing_application: ['Попълнете данните за покупка на изплащане.'] }
  }

  if (!leasingOptions.term_months.includes(Number(application.term_months))) {
    errors['leasing_application.term_months'] = ['Избраният срок не се поддържа.']
  }

  if (
    typeof application.down_payment !== 'string'
    || !/^\d+(?:\.\d{1,2})?$/.test(application.down_payment)
    || Number(application.down_payment) > Number(grandTotal)
  ) {
    errors['leasing_application.down_payment'] = ['Желаната първоначална вноска е невалидна.']
  }

  if (!leasingOptions.contact_methods.some(option => option.value === application.contact_method)) {
    errors['leasing_application.contact_method'] = ['Избраният начин за контакт не се поддържа.']
  }

  if (
    application.contact_time
    && !leasingOptions.contact_time_slots.some(option => option.value === application.contact_time)
  ) {
    errors['leasing_application.contact_time'] = ['Избраното време за контакт не се поддържа.']
  }

  if (application.consent !== true) {
    errors['leasing_application.consent'] = ['Необходимо е съгласие за обработване на заявката.']
  }

  return errors
}

function paymentPresentation(method, state, redirectUrl = null, instructions = null) {
  const safeRedirect = typeof redirectUrl === 'string' && redirectUrl.startsWith('https://')
    ? redirectUrl
    : null
  const labels = {
    pending: method === 'bank_transfer'
      ? 'Очаква се банков превод'
      : method === 'leasing'
        ? 'Заявката е получена'
        : method === 'cash_on_delivery'
          ? 'Плащане при доставка'
          : 'Очаква плащане',
    authorized: 'Плащането е разрешено',
    failed: 'Плащането е неуспешно',
    cancelled: 'Плащането е отказано',
    paid: 'Платено',
    refunded: 'Възстановено плащане',
    processing: 'Плащането се обработва',
    indeterminate: 'Непотвърден резултат',
  }
  const messages = {
    pending: method === 'bank_transfer'
      ? 'Очакваме плащане по банков път.'
      : method === 'leasing'
        ? 'Получихме заявката Ви за покупка на изплащане.'
        : method === 'cash_on_delivery'
          ? 'Ще заплатите сумата при получаване на поръчката.'
          : 'Завършете плащането само чрез изричното действие.',
    authorized: 'Плащането е разрешено и очаква окончателно потвърждение.',
    failed: 'Плащането не беше прието.',
    cancelled: 'Плащането беше отказано.',
    paid: 'Плащането е потвърдено.',
    refunded: 'Плащането е възстановено.',
    processing: 'Платежният опит се обработва.',
    indeterminate: 'Резултатът от плащането още не е потвърден.',
  }
  let action = { type: 'none', label: null, available: false }

  if (['pending', 'authorized'].includes(state) && safeRedirect) {
    action = {
      type: 'continue_payment',
      label: 'Продължи към плащане',
      available: true,
    }
  } else if (['failed', 'cancelled'].includes(state) && method === 'card') {
    action = {
      type: 'retry_payment',
      label: 'Опитай плащането отново',
      available: true,
    }
  }

  return {
    state,
    status_label: labels[state] || 'Неизвестно',
    message: messages[state] || 'Няма налична допълнителна информация за плащането.',
    action,
    redirect_url: action.type === 'continue_payment' ? safeRedirect : null,
    instructions,
    currency: 'EUR',
  }
}

function paymentAttemptResponse(orderNumber, paymentState, replayed = false) {
  const presentation = paymentPresentation(
    'card',
    paymentState,
    ['pending', 'authorized'].includes(paymentState)
      ? state.scenario.card_redirect_url
      : null,
  )

  return {
    reference: `PAY-${orderNumber}`,
    status: paymentState === 'indeterminate'
      ? 'indeterminate'
      : paymentState === 'processing'
        ? 'processing'
        : ['failed', 'cancelled'].includes(paymentState)
          ? 'failed'
          : 'completed',
    replayed,
    payment: {
      status: paymentState,
      amount: '1006.80',
      currency: 'EUR',
      method: {
        code: 'card',
        name: 'Плащане с карта',
      },
      redirect_url: presentation.redirect_url,
      instructions: null,
      presentation,
    },
  }
}

const server = createServer(async (request, response) => {
  const url = new URL(request.url || '/', FIXTURE_API_URL)
  const origin = request.headers.origin || null

  if (origin && origin !== FIXTURE_ORIGIN) {
    send(response, 403, safeError('forbidden', 403).body, origin)
    return
  }

  if (request.method === 'OPTIONS') {
    response.writeHead(204, corsHeaders(origin))
    response.end()
    return
  }

  const token = bearerToken(request)
  const cartSession = request.headers['x-cart-session'] || null
  state.recordRequest({
    method: request.method,
    path: url.pathname,
    cartSession,
    bearerToken: token,
    idempotencyKey: request.headers['idempotency-key'],
    origin,
  })

  if (request.method === 'GET' && url.pathname === '/health') {
    send(response, 200, { status: 'ok' }, origin)
    return
  }

  if (request.method === 'GET' && url.pathname === '/api/v1/content/homepage') {
    send(response, 200, { data: null }, origin)
    return
  }

  if (request.method === 'GET' && url.pathname === '/api/v1/home') {
    send(response, 200, {
      data: {
        hero_banners: [],
        featured_categories: [FIXTURE_CATEGORY],
        featured_products: [FIXTURE_PRODUCT],
        new_products: [],
        bestsellers: [],
        promotional_products: [],
        latest_articles: [],
      },
    }, origin)
    return
  }

  if (request.method === 'GET' && url.pathname === '/api/v1/products') {
    send(response, 200, {
      data: [FIXTURE_PRODUCT],
      links: {},
      meta: {
        current_page: 1,
        last_page: 1,
        per_page: 24,
        total: 1,
      },
      filters: [],
      active_filters: [],
      price_filter: null,
    }, origin)
    return
  }

  if (request.method === 'GET' && url.pathname === `/api/v1/products/${FIXTURE_PRODUCT.slug}`) {
    send(response, 200, {
      data: {
        ...FIXTURE_PRODUCT,
        description: 'Подробно описание на тестовия продукт.',
        images: [],
        attributes: [],
        specification_groups: [],
        related_products: [],
        accessory_products: [],
        seo: {},
        structured_data: {},
      },
    }, origin)
    return
  }

  if (request.method === 'GET' && url.pathname === '/api/v1/navigation/categories') {
    send(response, 200, { data: [FIXTURE_CATEGORY] }, origin)
    return
  }

  if (request.method === 'GET' && url.pathname === `/api/v1/categories/${FIXTURE_CATEGORY.slug}`) {
    send(response, 200, { data: FIXTURE_CATEGORY }, origin)
    return
  }

  if (request.method === 'GET' && url.pathname === `/api/v1/categories/${FIXTURE_CATEGORY.slug}/products`) {
    send(response, 200, {
      data: [FIXTURE_PRODUCT],
      links: {},
      meta: {
        current_page: 1,
        last_page: 1,
        per_page: 24,
        total: 1,
      },
      filters: [],
      active_filters: [],
      price_filter: null,
    }, origin)
    return
  }

  if (request.method === 'POST' && url.pathname === '/__test/reset') {
    state.reset()
    send(response, 200, { data: state.snapshot() }, origin)
    return
  }

  if (request.method === 'POST' && url.pathname === '/__test/scenario') {
    send(response, 200, { data: state.configure(await bodyOf(request)) }, origin)
    return
  }

  if (request.method === 'GET' && url.pathname === '/__test/requests') {
    send(response, 200, { data: state.snapshot().requests }, origin)
    return
  }

  if (request.method === 'GET' && url.pathname === '/__test/state') {
    send(response, 200, { data: state.snapshot() }, origin)
    return
  }

  if (request.method === 'GET' && url.pathname === '/api/v1/cart') {
    await wait(state.scenario.get_delay_ms)

    if (state.scenario.fail_next_get) {
      state.configure({ fail_next_get: false })
      const failure = safeError('request_failed', 503)
      send(response, failure.status, failure.body, origin)
      return
    }

    const resolved = currentCart(request, true)
    send(response, 200, { data: resolved.entry.cart }, origin)
    return
  }

  if (request.method === 'DELETE' && url.pathname === '/api/v1/cart') {
    await wait(state.scenario.mutation_delay_ms)
    if (mutationFailure(response, origin)) return

    const resolved = currentCart(request)
    resolved.entry.cart = {
      ...resolved.entry.cart,
      items: [],
      bundle_items: [],
      coupon_code: null,
      applied_promotions: [],
      gift_products: [],
      promotion_discount_total: '0.00',
      shipping_discount: '0.00',
      readiness: {
        can_checkout: false,
        issues_count: 0,
        has_product_issues: false,
        has_stock_issues: false,
      },
    }
    send(response, 200, { data: recalculateCart(resolved.entry.cart) }, origin)
    return
  }

  if (request.method === 'POST' && url.pathname === '/api/v1/cart/items') {
    await wait(state.scenario.mutation_delay_ms)
    if (mutationFailure(response, origin)) return

    const input = await bodyOf(request)
    const resolved = currentCart(request)
    const existing = resolved.entry.cart.items.find(item => item.product_id === FIXTURE_PRODUCT.id && !item.is_gift)
    const quantity = Number(input.quantity || 1)

    if (existing) {
      existing.quantity += quantity
    } else {
      const seeded = makeCartFixture(resolved.sessionId, 'product').items[0]
      seeded.quantity = quantity
      resolved.entry.cart.items.push(seeded)
    }

    resolved.entry.cart.readiness = {
      can_checkout: true,
      issues_count: 0,
      has_product_issues: false,
      has_stock_issues: false,
    }
    send(response, 200, { data: recalculateCart(resolved.entry.cart) }, origin)
    return
  }

  const itemRoute = routePattern(url.pathname, /^\/api\/v1\/cart\/items\/(\d+)$/)
  if (itemRoute && request.method === 'PATCH') {
    await wait(state.scenario.mutation_delay_ms)
    if (mutationFailure(response, origin)) return

    const input = await bodyOf(request)
    const resolved = currentCart(request)
    const line = resolved.entry.cart.items.find(item => item.id === Number(itemRoute[0]))

    if (!line || line.is_gift) {
      const failure = safeError(line?.is_gift ? 'cart_gift_line_immutable' : 'cart_product_unavailable', 409)
      send(response, failure.status, failure.body, origin)
      return
    }

    line.quantity = Number(input.quantity)
    send(response, 200, { data: recalculateCart(resolved.entry.cart) }, origin)
    return
  }

  if (itemRoute && request.method === 'DELETE') {
    await wait(state.scenario.mutation_delay_ms)
    if (mutationFailure(response, origin)) return

    const resolved = currentCart(request)
    const line = resolved.entry.cart.items.find(item => item.id === Number(itemRoute[0]))

    if (line?.is_gift) {
      const failure = safeError('cart_gift_line_immutable', 409)
      send(response, failure.status, failure.body, origin)
      return
    }

    resolved.entry.cart.items = resolved.entry.cart.items.filter(item => item.id !== Number(itemRoute[0]))
    send(response, 200, { data: recalculateCart(resolved.entry.cart) }, origin)
    return
  }

  if (request.method === 'POST' && url.pathname === '/api/v1/cart/coupon') {
    await wait(state.scenario.mutation_delay_ms)
    if (mutationFailure(response, origin)) return

    const input = await bodyOf(request)
    const resolved = currentCart(request)

    if (input.code === 'INVALID') {
      const failure = safeError('validation_error', 422)
      send(response, failure.status, failure.body, origin)
      return
    }

    resolved.entry.cart.coupon_code = String(input.code || '').toUpperCase()
    resolved.entry.cart.promotion_discount_total = '10.00'
    send(response, 200, { data: recalculateCart(resolved.entry.cart) }, origin)
    return
  }

  if (request.method === 'DELETE' && url.pathname === '/api/v1/cart/coupon') {
    await wait(state.scenario.mutation_delay_ms)
    if (mutationFailure(response, origin)) return

    const resolved = currentCart(request)
    resolved.entry.cart.coupon_code = null
    resolved.entry.cart.promotion_discount_total = '0.00'
    send(response, 200, { data: recalculateCart(resolved.entry.cart) }, origin)
    return
  }

  if (request.method === 'POST' && url.pathname === '/api/v1/cart/bundles') {
    await wait(state.scenario.mutation_delay_ms)
    if (mutationFailure(response, origin)) return

    const input = await bodyOf(request)
    const resolved = currentCart(request)
    const item = {
      id: 601,
      bundle_id: FIXTURE_BUNDLE.id,
      bundle_name: FIXTURE_BUNDLE.name,
      selected_items: Array.isArray(input.selected_items) && input.selected_items.length
        ? input.selected_items
        : [{
            component_group: 'base',
            product_id: FIXTURE_PRODUCT.id,
            name: FIXTURE_PRODUCT.name,
            quantity: 1,
          }],
      quantity: Number(input.quantity || 1),
      unit_price: FIXTURE_BUNDLE.price,
      total_price: FIXTURE_BUNDLE.price,
      original_price: FIXTURE_BUNDLE.original_price,
      savings: FIXTURE_BUNDLE.savings,
      readiness: {
        is_eligible: true,
        can_checkout: true,
        issues: [],
        stock: {
          tracked: true,
          requested_quantity: Number(input.quantity || 1),
          available_quantity: 5,
          max_purchasable_quantity: 5,
          is_sufficient: true,
        },
      },
    }
    resolved.entry.cart.bundle_items = [item]
    resolved.entry.cart.readiness.can_checkout = true
    send(response, 200, { data: recalculateCart(resolved.entry.cart) }, origin)
    return
  }

  const bundleRoute = routePattern(url.pathname, /^\/api\/v1\/cart\/bundles\/(\d+)$/)
  if (bundleRoute && request.method === 'PATCH') {
    await wait(state.scenario.mutation_delay_ms)
    if (mutationFailure(response, origin)) return

    const input = await bodyOf(request)
    const resolved = currentCart(request)
    const item = resolved.entry.cart.bundle_items.find(line => line.id === Number(bundleRoute[0]))
    if (item) item.quantity = Number(input.quantity || item.quantity)
    send(response, 200, { data: recalculateCart(resolved.entry.cart) }, origin)
    return
  }

  if (bundleRoute && request.method === 'DELETE') {
    await wait(state.scenario.mutation_delay_ms)
    if (mutationFailure(response, origin)) return

    const resolved = currentCart(request)
    resolved.entry.cart.bundle_items = resolved.entry.cart.bundle_items
      .filter(item => item.id !== Number(bundleRoute[0]))
    send(response, 200, { data: recalculateCart(resolved.entry.cart) }, origin)
    return
  }

  if (request.method === 'POST' && url.pathname === '/api/v1/cart/email') {
    const resolved = currentCart(request)
    send(response, 200, { data: resolved.entry.cart }, origin)
    return
  }

  if (request.method === 'POST' && url.pathname === '/api/v1/cart/recover') {
    const input = await bodyOf(request)

    if (input.capability !== 'A'.repeat(43)) {
      send(response, 404, {
        success: false,
        error: {
          code: 'cart_recovery_unavailable',
          message: 'Recovery link is unavailable.',
          details: null,
        },
      }, origin, {
        'Cache-Control': 'private, no-store, max-age=0',
        Pragma: 'no-cache',
      })
      return
    }

    const resolved = currentCart(request, true)
    send(response, 200, { data: resolved.entry.cart }, origin, {
      'Cache-Control': 'private, no-store, max-age=0',
      Pragma: 'no-cache',
    })
    return
  }

  if (request.method === 'POST' && url.pathname === '/api/v1/auth/login') {
    const input = await bodyOf(request)
    const selectedToken = input.email === 'customer-b@example.test'
      ? 'fixture-token-user-b'
      : 'fixture-token-user-a'
    send(response, 200, {
      data: {
        token: selectedToken,
        user: selectedToken.endsWith('user-b')
          ? {
              id: 2,
              first_name: 'Втори',
              last_name: 'Потребител',
              name: 'Втори Потребител',
              email: 'customer-b@example.test',
              roles: ['customer'],
              profile: null,
            }
          : {
              id: 1,
              first_name: 'Тест',
              last_name: 'Потребител',
              name: 'Тест Потребител',
              email: 'customer@example.test',
              roles: ['customer'],
              profile: null,
            },
      },
    }, origin)
    return
  }

  if (request.method === 'GET' && url.pathname === '/api/v1/auth/me') {
    if (!token) {
      const failure = safeError('unauthenticated', 401)
      send(response, failure.status, failure.body, origin)
      return
    }

    send(response, 200, {
      data: token.endsWith('user-b')
        ? {
            id: 2,
            first_name: 'Втори',
            last_name: 'Потребител',
            name: 'Втори Потребител',
            email: 'customer-b@example.test',
            roles: ['customer'],
            profile: null,
          }
        : {
            id: 1,
            first_name: 'Тест',
            last_name: 'Потребител',
            name: 'Тест Потребител',
            email: 'customer@example.test',
            roles: ['customer'],
            profile: null,
          },
    }, origin)
    return
  }

  if (request.method === 'POST' && url.pathname === '/api/v1/auth/logout') {
    send(response, 200, { data: { logged_out: true } }, origin)
    return
  }

  if (request.method === 'POST' && url.pathname === '/api/v1/compare/merge') {
    send(response, 200, {
      data: { id: 1, max_products: 4, items_count: 0, items: [] },
    }, origin)
    return
  }

  if (request.method === 'GET' && url.pathname === '/api/v1/compare/list') {
    send(response, 200, {
      data: { id: 1, max_products: 4, items_count: 0, items: [] },
    }, origin)
    return
  }

  if (request.method === 'GET' && url.pathname === '/api/v1/account') {
    send(response, 200, {
      data: {
        profile: {
          name: token?.endsWith('user-b') ? 'Втори Потребител' : 'Тест Потребител',
        },
        orders_summary: { total_orders: 0 },
        wishlist_summary: { items_count: 0 },
      },
    }, origin)
    return
  }

  if (request.method === 'GET' && url.pathname === '/api/v1/account/wishlists') {
    send(response, 200, { data: [] }, origin)
    return
  }

  if (request.method === 'POST' && url.pathname === '/api/v1/marketing/events') {
    state.recordAnalytics(await bodyOf(request))
    send(response, 202, { data: { accepted: true } }, origin)
    return
  }

  if (request.method === 'GET' && url.pathname === `/api/v1/bundles/${FIXTURE_BUNDLE.slug}`) {
    send(response, 200, { data: FIXTURE_BUNDLE }, origin)
    return
  }

  if (request.method === 'GET' && url.pathname === '/api/v1/shipping/providers') {
    send(response, 200, {
      data: [{ id: 1, name: 'Ръчна доставка', code: 'manual', status: 'active' }],
    }, origin)
    return
  }

  if (request.method === 'GET' && url.pathname === '/api/v1/payments/methods') {
    const methods = [
      {
        id: 1,
        name: 'Наложен платеж',
        code: 'cash_on_delivery',
        type: 'offline',
        description: null,
        instructions: null,
        sort_order: 1,
      },
      {
        id: 2,
        name: 'Банков превод',
        code: 'bank_transfer',
        type: 'offline',
        description: null,
        instructions: 'Ще получите данни за банков превод.',
        sort_order: 2,
      },
    ]

    if (state.scenario.leasing_enabled) {
      methods.push({
        id: 4,
        name: 'Покупка на изплащане',
        code: 'leasing',
        type: 'leasing',
        description: 'Изпращане на заявка за покупка на изплащане. Наш служител ще се свърже с клиента.',
        instructions: 'Получихме заявката Ви за покупка на изплащане. Наш служител ще се свърже с Вас.',
        sort_order: 4,
        options: leasingOptions,
      })
    }

    if (state.scenario.card_enabled) {
      methods.push({
        id: 3,
        name: 'Плащане с карта',
        code: 'card',
        type: 'online',
        description: 'Сигурно онлайн плащане.',
        instructions: null,
        sort_order: 3,
      })
    }

    send(response, 200, {
      data: methods,
    }, origin)
    return
  }

  if (request.method === 'GET' && url.pathname === '/api/v1/shipping/offices') {
    send(response, 200, { data: [] }, origin)
    return
  }

  if (request.method === 'POST' && url.pathname === '/api/v1/shipping/calculate') {
    send(response, 200, {
      data: {
        shipping_price: '6.90',
        estimated_delivery: '1-2 работни дни',
        provider: 'manual',
        method: 'address',
      },
    }, origin)
    return
  }

  if (request.method === 'GET' && url.pathname === '/api/v1/checkout/confirmation') {
    const confirmation = state.resolveConfirmation(
      cookieValue(request, 'mc_checkout_confirmation'),
    )
    const privacyHeaders = {
      'Cache-Control': 'private, no-store, max-age=0',
      Pragma: 'no-cache',
    }

    if (!confirmation) {
      send(response, 404, safeError('checkout_confirmation_unavailable', 404).body, origin, {
        ...privacyHeaders,
        'Set-Cookie': 'mc_checkout_confirmation=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax',
      })
      return
    }

    send(response, 200, { data: confirmation }, origin, privacyHeaders)
    return
  }

  if (request.method === 'POST' && url.pathname === '/api/v1/checkout/payment-attempts') {
    const capability = state.resolvePaymentRetry(
      cookieValue(request, 'mc_payment_retry'),
    )

    if (!capability) {
      send(response, 404, safeError('payment_retry_unavailable', 404).body, origin, {
        'Cache-Control': 'private, no-store, max-age=0',
        Pragma: 'no-cache',
      })
      return
    }

    const idempotency = state.inspectPaymentAttempt(
      request.headers['idempotency-key'],
      capability.orderNumber,
    )

    if (idempotency.error) {
      const status = idempotency.error === 'payment_attempt_idempotency_key_invalid' ? 422 : 409
      send(response, status, safeError(idempotency.error, status).body, origin)
      return
    }

    if (idempotency.replay) {
      send(response, 200, {
        data: {
          ...idempotency.replay.result,
          replayed: true,
        },
      }, origin, {
        'Cache-Control': 'private, no-store, max-age=0',
        Pragma: 'no-cache',
      })
      return
    }

    const result = paymentAttemptResponse(
      capability.orderNumber,
      state.scenario.card_retry_state,
    )
    state.completePaymentAttempt(idempotency.pending, result)

    if (state.scenario.lose_next_payment_attempt_response) {
      state.configure({ lose_next_payment_attempt_response: false })
      send(response, 503, safeError('request_failed', 503).body, origin, {
        'Cache-Control': 'private, no-store, max-age=0',
        Pragma: 'no-cache',
      })
      return
    }

    send(response, 201, { data: result }, origin, {
      'Cache-Control': 'private, no-store, max-age=0',
      Pragma: 'no-cache',
    })
    return
  }

  if (request.method === 'POST' && url.pathname === '/api/v1/checkout') {
    const input = await bodyOf(request)

    if (input.payment_method === 'card' && !state.scenario.card_enabled) {
      send(response, 422, {
        success: false,
        error: {
          code: 'payment_method_unavailable',
          message: 'Избраният начин на плащане не е наличен.',
          details: null,
        },
      }, origin)
      return
    }

    if (input.payment_method === 'leasing' && !state.scenario.leasing_enabled) {
      send(response, 422, {
        success: false,
        error: {
          code: 'payment_method_unavailable',
          message: 'Избраният начин на плащане не е наличен.',
          details: null,
        },
      }, origin)
      return
    }

    if (input.payment_method !== 'leasing' && Object.hasOwn(input, 'leasing_application')) {
      send(response, 422, {
        message: 'Данните са невалидни.',
        errors: {
          leasing_application: ['Данни за покупка на изплащане са позволени само при избран този начин на плащане.'],
        },
      }, origin)
      return
    }

    const idempotency = state.inspectCheckout(
      request.headers['idempotency-key'],
      cartSession,
      input,
    )

    if (idempotency.error) {
      const status = idempotency.error === 'checkout_idempotency_key_invalid' ? 422 : 409
      const failure = safeError(idempotency.error, status)
      send(response, failure.status, failure.body, origin)
      return
    }

    if (idempotency.replay) {
      const confirmationToken = state.issueConfirmation(idempotency.replay.checkout.confirmation)
      const retryToken = !token && idempotency.replay.checkout.response.payment_method === 'card'
        ? state.issuePaymentRetry(idempotency.replay.checkout.response.order_number)
        : null
      const cookies = [
        `mc_checkout_confirmation=${confirmationToken}; Max-Age=7200; Path=/; HttpOnly; SameSite=Lax`,
      ]

      if (retryToken) {
        cookies.push(
          `mc_payment_retry=${retryToken}; Max-Age=3600; Path=/api/v1/checkout/payment-attempts; HttpOnly; SameSite=Lax`,
        )
      }

      send(response, 201, {
        data: {
          ...idempotency.replay.checkout.response,
          idempotent_replay: true,
        },
      }, origin, {
        'Cache-Control': 'private, no-store, max-age=0',
        Pragma: 'no-cache',
        'Set-Cookie': cookies,
      })
      return
    }

    if (state.scenario.next_checkout_error) {
      const failure = safeError(state.scenario.next_checkout_error, 409)
      send(response, failure.status, failure.body, origin)
      return
    }

    const resolved = currentCart(request)

    if (!resolved.entry.cart.readiness.can_checkout) {
      const failure = safeError('cart_not_ready', 409)
      send(response, failure.status, failure.body, origin)
      return
    }

    const orderNumber = `MC-FIXTURE-${String(state.snapshot().orders_created + 1).padStart(4, '0')}`
    const grandTotal = (Number(resolved.entry.cart.subtotal) + 6.9).toFixed(2)
    const paymentMethod = typeof input.payment_method === 'string'
      ? input.payment_method
      : 'cash_on_delivery'
    const paymentState = paymentMethod === 'card'
      ? state.scenario.card_payment_state
      : 'pending'
    const paymentInstructions = paymentMethod === 'leasing'
      ? 'Получихме заявката Ви за покупка на изплащане. Наш служител ще се свърже с Вас.'
      : paymentMethod === 'bank_transfer'
        ? 'Ще получите данни за банковия превод по имейл.'
        : null
    const paymentRedirect = paymentMethod === 'card'
      ? state.scenario.card_redirect_url
      : null
    const leasingErrors = paymentMethod === 'leasing'
      ? validateLeasingApplication(input, grandTotal)
      : {}

    if (Object.keys(leasingErrors).length > 0) {
      send(response, 422, {
        message: 'Данните са невалидни.',
        errors: leasingErrors,
      }, origin)
      return
    }
    const confirmation = {
      order_number: orderNumber,
      grand_total: grandTotal,
      currency: resolved.entry.cart.currency,
      order_status: 'pending',
      payment_status: 'pending',
      payment_method: {
        code: paymentMethod,
        name: paymentMethod === 'bank_transfer'
          ? 'Банков превод'
          : paymentMethod === 'leasing'
            ? 'Покупка на изплащане'
            : 'Наложен платеж',
      },
      customer_email_masked: maskEmail(input.email),
      payment: {
        redirect_url: null,
        instructions: paymentMethod === 'leasing'
          ? 'Получихме заявката Ви за покупка на изплащане. Наш служител ще се свърже с Вас.'
          : null,
      },
      created_at: '2030-01-01T00:00:00.000000Z',
    }
    confirmation.payment_status = paymentState
    confirmation.payment.presentation = paymentPresentation(
      paymentMethod,
      paymentState,
      paymentRedirect,
      paymentInstructions,
    )

    if (paymentMethod === 'card') {
      confirmation.payment_method.name = 'Плащане с карта'
    }

    const checkout = {
      response: {
        accepted: true,
        order_number: orderNumber,
        grand_total: grandTotal,
        currency: resolved.entry.cart.currency,
        payment_method: paymentMethod,
        payment_status: 'pending',
      },
      confirmation,
    }
    checkout.response.payment_status = paymentState
    state.completeCheckout(idempotency.pending, checkout, {
      leasingApplication: paymentMethod === 'leasing',
      providerInvocation: paymentMethod === 'card',
    })
    const confirmationToken = state.issueConfirmation(confirmation)
    const retryToken = !token && paymentMethod === 'card'
      ? state.issuePaymentRetry(orderNumber)
      : null

    if (state.scenario.lose_next_checkout_response) {
      state.configure({ lose_next_checkout_response: false })
      response.destroy()
      return
    }

    const cookies = [
      `mc_checkout_confirmation=${confirmationToken}; Max-Age=7200; Path=/; HttpOnly; SameSite=Lax`,
    ]

    if (retryToken) {
      cookies.push(
        `mc_payment_retry=${retryToken}; Max-Age=3600; Path=/api/v1/checkout/payment-attempts; HttpOnly; SameSite=Lax`,
      )
    }

    send(response, 201, {
      data: {
        ...checkout.response,
        idempotent_replay: false,
      },
    }, origin, {
      'Cache-Control': 'private, no-store, max-age=0',
      Pragma: 'no-cache',
      'Set-Cookie': cookies,
    })
    return
  }

  send(response, 404, safeError('not_found', 404).body, origin)
})

if (fileURLToPath(import.meta.url) === process.argv[1]) {
  server.listen(port, '127.0.0.1', () => {
    process.stdout.write(`Cart fixture listening on ${FIXTURE_API_URL}\n`)
  })
}

export { server, state }
