import { createServer } from 'node:http'
import { fileURLToPath } from 'node:url'
import {
  FIXTURE_API_URL,
  FIXTURE_BUNDLE,
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
    'Access-Control-Allow-Headers': 'Authorization, Content-Type, X-Cart-Session, X-Compare-Session, X-Marketing-Session',
    'Access-Control-Allow-Methods': 'GET, POST, PATCH, DELETE, OPTIONS',
    'Access-Control-Expose-Headers': 'Content-Type',
    'Access-Control-Allow-Credentials': 'true',
    Vary: 'Origin',
  }
}

function send(response, status, body, origin = null) {
  response.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    ...corsHeaders(origin),
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
    origin,
  })

  if (request.method === 'GET' && url.pathname === '/health') {
    send(response, 200, { status: 'ok' }, origin)
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
    send(response, 200, {
      data: [{
        id: 1,
        name: 'Наложен платеж',
        code: 'cash_on_delivery',
        type: 'offline',
        description: null,
        instructions: null,
        sort_order: 1,
      }],
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

  if (request.method === 'POST' && url.pathname === '/api/v1/checkout') {
    const failure = safeError(state.scenario.next_checkout_error || 'cart_not_ready', 409)
    send(response, failure.status, failure.body, origin)
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
