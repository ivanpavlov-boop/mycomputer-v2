# Cart Architecture, Safety and Gap Audit

**Phase:** Commerce Phase 1A

**Audit type:** Read-only architecture and safety audit

**Repository baseline:** `de8e73cd2af31d3816fe5e72dd5c3c82f5277243`

**Implementation status:** No production behavior changed

This document describes the code and tests present at the baseline above. It
uses these terms deliberately:

- **Confirmed current behavior:** directly supported by repository evidence.
- **Confirmed defect:** an evidenced behavior that violates an ownership,
  correctness or reliability boundary.
- **Likely risk:** the code exposes a risk, but runtime behavior still needs
  controlled verification.
- **Open question:** repository evidence is insufficient; an exact verification
  procedure is provided.
- **Recommended future behavior:** a proposal only, not an implemented feature.

The machine-readable companion is
[`CART_GAP_REGISTER.json`](CART_GAP_REGISTER.json).

## 1. Executive Summary

The repository already contains a substantial commerce foundation: bearer
session carts, regular and bundle lines, coupons, automatic gifts, abandoned
cart recovery, checkout, orders, stock reduction, shipping, payment
abstractions and backend feature tests. Checkout recalculates server-side,
revalidates stock and reduces stock under row locks.

The implementation is **not ready for public commerce enablement**. Two
confirmed blockers require attention before release:

1. Checkout does not apply the normal cart ownership rejection and can reassign
   another user's supplied cart.
2. Checkout has no idempotency key or cart-level serialization, so duplicate
   requests can create duplicate orders and payment attempts.

High-risk gaps also exist in guest-to-user transition, session persistence,
frontend split-brain state, price authority, stale displayed prices, payment
initiation, promotion concurrency and recovery semantics. The audit records 26
open findings:

| Severity | Count |
| --- | ---: |
| Blocker | 2 |
| High | 8 |
| Medium | 12 |
| Low | 2 |
| Info | 2 |
| **Total** | **26** |

Confidence distribution:

| Confidence | Count |
| --- | ---: |
| Confirmed | 23 |
| Likely | 2 |
| Open question | 1 |

Deployment routing currently keeps public cart and checkout pages disabled.
That is an appropriate release barrier until Commerce Phases 1B, 1C and 1D
complete their gates.

## 2. Scope and Method

The audit traced:

- Laravel routes, request validation, controllers and API resources;
- cart, bundle, checkout, stock, promotion, payment, shipping and email
  services;
- related models, jobs, commands, events and schedules;
- every relevant migration and database constraint;
- Nuxt cart state, API composable, pages and shared cart components;
- backend feature tests and frontend test scripts;
- deployment route ownership.

Method:

1. Establish the exact baseline and clean branch.
2. Trace each endpoint from route through validation, authorization, service,
   database write and response.
3. Compare cart pricing and eligibility with Product public contracts.
4. Trace guest, authenticated, recovery and checkout lifecycles.
5. Inspect schema constraints against concurrency and idempotency claims.
6. Read test assertions rather than infer coverage from filenames.
7. Record only repository-evidenced behavior; isolate runtime questions.

This phase did not execute a production checkout, contact a payment or shipping
provider, access a deployed environment, or modify application behavior.

## 3. Current Cart Architecture

Primary boundaries:

| Boundary | Current implementation |
| --- | --- |
| Identity | Opaque `X-Cart-Session` bearer value |
| Session creation | Server-generated UUID when the header is absent |
| Persistence | `carts`, `cart_items`, `cart_bundle_items` |
| Product add authority | Laravel `CartService` |
| Bundle add authority | `BundleCartService` |
| Price recalculation | Cart and bundle services; checkout invokes recalculation |
| Promotions | `PromotionEngineService` during cart resource evaluation and checkout |
| Stock authority | Final assertion and locked reduction in `StockReservationService` |
| Checkout transaction | `CheckoutService` database transaction |
| Frontend state | Pinia store with backend cart plus local in-memory fallback |
| Guest persistence | Nuxt `useState`; no durable browser mechanism identified |
| Recovery | Scheduled abandoned-cart record, email capability, restore endpoint |

Guest cart flow as implemented:

```text
Product page
  -> POST /api/v1/cart/items
  -> CartService resolves supplied session or creates a UUID
  -> carts row
  -> cart_items row
  -> CartService recalculates stored line totals
  -> CartResource returns cart_session_id and lines
  -> Nuxt writes backend cart and runtime session state
```

## 4. Guest Cart Lifecycle

**Confirmed current behavior**

- No session header causes generation of a UUID and creation of an active cart
  with an expiry timestamp.
- A non-empty client-supplied session is used directly as the lookup key.
- The session identifier is returned in the Cart resource.
- Guest possession of the identifier is the access capability.
- Regular add checks Product public visibility and purchase availability.
- Checkout converts and clears the cart after order creation.

**Confirmed defects**

- Resolution does not reject expired or converted carts.
- The client-supplied identifier has no application-level format or length
  validation.
- Runtime-only frontend storage does not provide a reliable hard-refresh,
  new-tab or browser-restart lifetime.

**Recommended future behavior**

Define a bounded opaque session format, expiry/rotation policy and SSR-safe
persistence mechanism. Treat the session as a credential in logging,
analytics, URLs and error handling.

## 5. Authenticated Cart Lifecycle

Authenticated cart requests still resolve by the supplied cart session. The
normal Cart controller then:

1. rejects a cart owned by another user;
2. associates an unowned cart with the authenticated user;
3. refreshes the customer contact value where available;
4. returns the same Cart resource.

There is no query for a canonical active user cart and no unique database
constraint limiting a user to one active cart. Multiple devices can therefore
produce multiple active carts.

Implemented authenticated flow:

```text
Authenticated request
  -> resolve X-Cart-Session
  -> CartService firstOrCreate by session_id
  -> CartController ownership check
  -> reject another owner OR associate current user
  -> no merge with another active user cart
  -> CartResource response
```

The bundle controller calls cart resolution directly and does not reproduce the
Cart controller's authenticated ownership-association boundary for bundle
creation.

## 6. Guest-to-Authenticated Transition

| Scenario | Confirmed current result |
| --- | --- |
| Guest cart; user has no cart | Supplied guest cart is claimed by the user |
| Guest cart; user already has an active cart | Supplied guest cart is claimed; existing cart remains separate |
| Same Product in both carts | No merge or quantity reconciliation |
| Bundles in both carts | No merge |
| Coupon or gifts | No merge |
| Login on two devices | Multiple active user carts are possible |
| Logout | Cart session remains in frontend runtime state |
| Login as a different user | Normal cart API can reject; frontend silently falls back locally |

There is no implemented replacement or merge algorithm. Existing user-cart
items are not deleted, but can appear lost because the currently supplied
session selects a different cart.

Commerce Phase 1B must approve the transition contract before implementation.

## 7. API Endpoint Matrix

All paths below use the `/api/v1` prefix.

| Method and path | Action | Auth | Identity | Validation | Ownership | Rate limit | Writes | Response / errors |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `GET /cart` | `CartController@show` | Optional | Header | None | Rejects other authenticated owner | API only | May create/associate cart | Cart resource; 403 |
| `POST /cart/items` | `CartController@store` | Optional | Header | Product and quantity request | Same controller boundary | API only | Cart/item and analytics dispatch | Cart resource; validation/eligibility errors |
| `PATCH /cart/items/{item}` | `CartController@update` | Optional | Header + item | Quantity | Item must belong to resolved cart | API only | Item quantity/totals | Cart resource; 404/validation |
| `DELETE /cart/items/{item}` | `CartController@destroy` | Optional | Header + item | Route model | Item must belong to resolved cart | API only | Deletes item | Cart resource; 404 |
| `DELETE /cart` | `CartController@clear` | Optional | Header | None | Same controller boundary | API only | Deletes regular and bundle lines | Cart resource |
| `POST /cart/coupon` | `CartController@applyCoupon` | Optional | Header | Coupon code | Same controller boundary | Coupon limiter | Updates coupon | Cart resource; coupon error |
| `DELETE /cart/coupon` | `CartController@removeCoupon` | Optional | Header | None | Same controller boundary | Coupon limiter | Clears coupon | Cart resource |
| `POST /cart/email` | `CartController@email` | Optional | Header | Contact value | Same controller boundary | Newsletter limiter | Cart/subscription context | Generic response |
| `POST /cart/recover/{token}` | `CartController@recover` | Optional | Recovery capability and optional header | Route capability | Recovery service rules; no user match | Newsletter limiter | Clears/rebuilds regular lines | Cart resource; invalid/expired errors |
| `POST /cart/bundles` | `CartBundleController@store` | Optional | Header | Bundle/options/quantity | No authenticated owner boundary equivalent | API only | Bundle line | Cart resource; inventory errors |
| `PATCH /cart/bundles/{item}` | `CartBundleController@update` | Optional | Header + item | Quantity/options | Item cart match | API only | Bundle line | Cart resource; 404/inventory |
| `DELETE /cart/bundles/{item}` | `CartBundleController@destroy` | Optional | Header + item | Route model | Item cart match | API only | Deletes bundle line | Cart resource; 404 |
| `POST /checkout` | `CheckoutController` | Optional | Header | Customer/shipping/payment | Reassigns instead of rejecting other owner | API only | Customer, order, lines, shipment, payment, stock, cart | Order resource; validation/stock/provider errors |
| `POST /cart/request-quote` | `CartQuoteController` | Required | Header | Quote fields | Null/same user accepted | Auth middleware | Quote records | Quote response; auth/ownership errors |
| `POST /payments/initiate` | Removed in Commerce Phase 1D.2A | N/A | N/A | N/A | Retired unsafe guest surface | N/A | None | Returns 404 |
| `POST /shipping/calculate` | `ShippingController@calculate` | No | Optional cart id/header | Shipping request | No cart owner check for direct id | API only | No intended cart write | Price result; validation/provider errors |

Payment webhook routes use provider signature, timestamp and replay validation;
they are separate from the unauthenticated initiation gap.

## 8. Cart Identity and Ownership

`carts.session_id` is unique and supports 255 characters. Generated values are
UUIDs, but supplied values are accepted whenever non-empty. The value is a
bearer credential because guest access requires no second secret.

Ownership is not centralized:

- Cart controller: rejects another authenticated owner.
- Checkout controller: assigns the current user without that rejection.
- Bundle create: resolves directly without the same boundary.
- Shipping calculation: can use a direct cart identifier without ownership.
- Recovery: can target a supplied session and replace its normal lines.

This inconsistency creates CART-001 and supporting ownership findings. The
future contract should be one service used by every cart-consuming endpoint.

## 9. Cart Data Model and Constraints

| Table | Relevant shape | Constraints and lifecycle |
| --- | --- | --- |
| `carts` | session, optional user/contact, status, expiry, coupon, timestamps | Unique session; user foreign key nulls on delete; status/expiry indexed; no soft delete; no one-active-cart-per-user constraint |
| `cart_items` | cart, product, quantity, unit/total decimals, gift/promotion fields | Cart cascade delete; Product cascade delete; unique cart+Product; no database quantity check |
| `cart_bundle_items` | cart, bundle, JSON selection, quantity, unit/total decimals | Cascading foreign keys; no unique bundle identity |
| `abandoned_cart_records` | cart/session context, snapshot, status, recovery capability, expiry and email timestamps | Recovery capability unique; session indexed, not unique; no soft delete |
| `promotion_redemptions` | promotion, optional user/session, order, amount | Indexes only for user/session scopes; no uniqueness enforcing one redemption |
| `orders` | unique order number, user/customer, statuses, money totals, timestamps | Order number unique; no cart id or checkout idempotency key |
| `order_items` | order, optional Product, snapshots, quantity, decimal amounts | Order cascade; Product null on delete |
| `customers` | contact and address profile | Contact email indexed, not unique |
| `payment_transactions` | order, provider/method, transaction id, amount/currency/status | Transaction id indexed, not unique; no request idempotency key |
| `shipments` | order/provider/method, address/office, price/status | Order relationship; lifecycle strings and timestamps |

Money columns use fixed two-decimal database fields, but service calculations
frequently cast to PHP floats before persisting. No stock reservation table was
identified; checkout performs assertion and immediate reduction.

Uniqueness conclusions:

- `carts.session_id`: enforced.
- regular `(cart_id, product_id)`: enforced.
- bundle identity: not enforced.
- order number: enforced.
- checkout/payment idempotency: not enforced.

## 10. Product Eligibility

| Stage | Visibility / availability behavior |
| --- | --- |
| Add regular item | Requires public Product and purchase-allowed availability |
| Update regular quantity | Verifies line ownership; does not revalidate Product |
| Cart view | Loads stored lines; no eligibility refresh |
| Recalculation | Rewrites price totals; no visibility refresh |
| Add/update bundle | Bundle inventory service validates active configuration and components |
| Checkout | Revalidates public Product, purchase availability and stock where required |

Therefore draft, pending, unpublished, hidden, inactive, deleted,
purchase-disabled or quantity-reduced Products can remain visible in an
existing cart until final checkout validation. Soft-deleted Product handling
also depends on relationship loading and is not represented as an explicit
line state.

Recommended future behavior is not silent deletion. A cart should expose a
deterministic unavailable state, explain the reason safely, prevent invalid
quantity mutation and retain checkout as final authority.

## 11. Pricing Authority

There are competing price paths:

| Surface | Current source |
| --- | --- |
| Public Product cards/details | `effectivePrice()` and date-aware `activeSalePrice()` |
| Regular cart line | `promo_price` when non-null, otherwise `price` |
| Bundle component pricing | `promo_price` when non-null, otherwise `price` |
| Cart display | Stored unit and total prices unless another mutation recalculates |
| Checkout | Recalculates through cart and bundle services |
| Promotion discount | Applies after cart/bundle subtotal calculation |

The client cannot submit a unit price, which is a strong existing boundary.
However, server authority is internally inconsistent. Future and expired
promotional values can be selected by cart pricing, and a Product price change
after add is not shown until a recalculation-triggering action or checkout.

Other observations:

- PHP float arithmetic precedes fixed-decimal persistence.
- Promotion discount is capped so the grand total does not become negative.
- API Product currency is EUR.
- The cart page contains a legacy hardcoded lev subtotal label.
- No canonical VAT presentation contract was identified across cart and
  checkout.

Commerce Phase 1B should define a money value object or decimal policy, one
authoritative customer-price method, currency invariants and customer-visible
price-change behavior.

## 12. Promotions, Coupons and Gifts

Confirmed strengths:

- Coupon matching normalizes case.
- Active dates, status, global limits and per-user/session rules are evaluated.
- Promotion discounts are bounded by available subtotal.
- Coupon removal and promotion recalculation paths exist.
- Automatic gifts are recalculated server-side.

Confirmed gaps:

- Usage validation and redemption recording are not atomic.
- Redemption identity is not protected by a unique database constraint.
- Paid and gift copies of one Product conflict with the regular-line unique
  identity.
- Gift availability is not finally stock-enforced until checkout.
- Recovered carts do not faithfully restore coupon and gift state.

Future tests must include last-slot concurrent redemptions, the same Product as
paid and gift, gift removal, rollback and session-to-user transition.

## 13. Bundles

Bundle lines store a JSON snapshot of selected components. Add and update use
bundle availability and inventory services. Checkout creates:

- an order bundle record;
- zero-priced component order items for fulfillment visibility;
- stock reductions for component Products.

Bundle price calculation shares the non-date-aware promotional price issue.
The cart bundle table has no unique identity, so repeated equivalent adds
create separate lines by design or accident; the intended semantics are not
documented.

Cross-session update/delete protection exists for bundle items. Bundle creation
does not share the normal authenticated cart ownership association/check.
Abandoned-cart detection and restore do not preserve bundles.

## 14. Stock and Availability

Availability and stock are separate concepts:

- Availability status controls whether purchase is allowed.
- Some statuses require tracked stock.
- Regular cart add/update does not cap to available quantity.
- Bundle add/update performs component inventory checks.
- Checkout checks every regular and bundle component.
- Stock reduction locks Product rows, rechecks quantity and updates
  availability.

The final locked reduction limits overselling, but there is no pre-checkout
reservation. Two checkouts can both pass an earlier assertion; the second
locked reduction should fail if the first consumes stock. This protects stock
but does not solve duplicate checkout when enough stock exists.

Backorder semantics are represented indirectly by whether a status requires
stock; a release contract should name and test them explicitly.

## 15. Expiry and Cart Status

The Cart model uses string status values including `active` and `converted`.
Cart resolution does not inspect status or expiry, and does not renew expiry on
activity. Checkout marks the cart converted after clearing lines.

The scheduler runs abandoned-cart detection and email processing. It expires
abandoned recovery records, but no general cart-expiry cleanup or cart-session
rotation task was identified.

Consequences:

- converted sessions can resolve again;
- expired rows can resolve again;
- a post-checkout request can reuse the old identity;
- multiple active carts can accumulate for a user;
- retention depends on undeclared operational cleanup.

## 16. Cart Recovery and Email

Current flow:

```text
Active cart older than threshold and containing regular items
  -> scheduled detector
  -> abandoned_cart_records snapshot and recovery capability
  -> scheduled email processing
  -> recovery route submits capability
  -> service clears/rebuilds normal cart lines
  -> checkout later marks record recovered
```

Confirmed safeguards:

- Capability generation uses a high-entropy random value.
- The database enforces capability uniqueness.
- Expired, suppressed and already recovered records are rejected.
- Email attachment and recovery routes use rate limiting.
- Public responses do not enumerate account existence.
- No reset capability or plain-text password behavior is involved.

Confirmed gaps:

- Capability is stored in plaintext and appears in a route path.
- Successful restore does not itself make the capability single-use.
- A supplied target session can be cleared without user ownership matching.
- Restore rebuilds normal Product lines only.
- Bundle-only carts are not detected.
- Bundle, coupon and automatic-gift state is not faithfully restored.
- Restore does not perform the complete add/checkout eligibility sequence.

No real customer data or recovery capability is included in this audit.

## 17. Frontend State Architecture

The Pinia store owns:

- `backendCart`, populated from Laravel;
- local `lines`, populated after caught backend failures;
- a backend-availability flag;
- computed count and subtotal;
- add, update, remove, clear, coupon and sync actions.

The fallback is silent. Backend and local lines can render together, while
count and subtotal prefer backend values whenever a backend cart exists.
Successful backend recovery does not merge or clear local lines. Checkout uses
the backend API and cannot submit local-only lines.

This is a confirmed split-brain design, not a durable offline cart.

## 18. SSR, Hydration and Persistence

`useCartApi` stores the cart session with Nuxt `useState`. That can remain
consistent within one SSR/hydration lifecycle, but no cookie, local storage,
session storage or other durable mechanism preserves it across a new runtime.

Expected behavior from the code:

| Event | Result |
| --- | --- |
| Client navigation | Runtime session generally retained |
| Hydration of same response | Shared Nuxt state intended |
| Hard refresh | New server/runtime state can create a new cart |
| New tab | No guaranteed shared cart identity |
| Browser restart | No persistence |
| Back/Forward | Depends on retained runtime/page cache |
| Login | Syncs current supplied session; no merge |
| Logout | Does not clear or rotate cart session |

A real browser suite is required to verify platform-specific behavior and
ensure no hydration mismatch.

## 19. Error and Offline Behavior

Backend errors are broadly caught by the cart store and converted into local
state. This hides:

- authorization failures;
- validation failures;
- Product eligibility changes;
- server outages;
- stale line ownership;
- checkout-incompatible local state.

The pages and controls do not consistently expose pending, retry or disabled
states. Quantity controls do not communicate the backend maximum or available
stock. Cart-to-checkout navigation is visible even when checkout cannot use the
rendered local lines.

Recommended future behavior is an explicit recoverable error state, not
pretending an authoritative server write succeeded.

## 20. Cart-to-Checkout Boundary

The frontend sends checkout details to the backend cart selected by
`X-Cart-Session`. Local fallback lines are absent from that request. The page
does not prevent rapid duplicate submission at the UI layer.

Backend checkout:

- resolves the cart;
- associates the authenticated user without rejecting another owner;
- recalculates;
- validates stock;
- creates order side effects.

The success navigation includes order context and customer contact data in
query parameters. Future behavior should use an authorized order reference and
fetch trusted status server-side.

## 21. Checkout, Order and Stock Boundary

Actual checkout sequence:

```text
Cart
  -> database transaction starts
  -> cart and bundle price recalculation
  -> automatic gift evaluation
  -> stock and Product eligibility assertion
  -> customer updateOrCreate by contact email
  -> promotion, loyalty and shipping calculation
  -> order
  -> regular order items
  -> bundle order record and component order items
  -> shipment creation/provider boundary
  -> payment transaction/provider initiation
  -> locked stock reduction
  -> cart lines cleared and cart marked converted
  -> promotion usage and recovery attribution
  -> events, jobs and email work dispatched
  -> transaction commits
```

Strong boundaries:

- empty cart rejection;
- server-side price recalculation;
- Product/availability revalidation;
- stock row locks and recheck;
- unique order number;
- one database transaction for durable checkout state.

Gaps:

- inconsistent ownership;
- no cart status/expiry gate;
- no idempotency;
- customer profile overwrite semantics;
- provider and dispatch boundaries inside the transaction;
- no durable stock reservation between review and commit.

## 22. Concurrency and Idempotency

| Scenario | Current protection | Remaining risk |
| --- | --- | --- |
| Two adds for same Product | Unique regular line | Read/update race, lost quantity or unique error |
| Two quantity updates | Last write | Lost update |
| Add and remove | No serialization | Timing-dependent final state |
| Equivalent bundle adds | No unique identity | Duplicate bundle lines |
| Two checkouts | Stock row locks only | Duplicate orders/payments if stock suffices |
| Stock changes during checkout | Locked final recheck | Earlier UI can be stale |
| Coupon last usage | Read then record | Limit overrun |
| Payment initiation retry | New transaction each call | Duplicate payment attempts |

No request idempotency key, unique checkout key, cart lock or payment
idempotency constraint was identified.

## 23. Security Review

**Confirmed strengths**

- Product price is not client-controlled.
- Cart item update/delete checks resolved-cart membership.
- Normal authenticated cart requests reject a different owner.
- Checkout performs final Product and stock checks.
- Payment webhooks have signature, timestamp and replay validation tests.
- Public recovery responses are generic and rate limited.

**Confirmed defects**

- Checkout ownership diverges from normal cart ownership.
- Payment initiation is unauthenticated and unscoped to an order owner.
- Recovery can overwrite a supplied cart session.
- Session format is unbounded at the application layer.
- Recovery capability is stored directly and used in a URL path.
- Checkout success URLs include customer context.

The audit contains no secrets, credentials, real contacts or deployed data.

## 24. Performance and Query Review

- Cart responses eager-load regular Products, availability, bundle relations
  and promotion context.
- Promotion evaluation occurs while building the Cart resource, so repeated
  reads can repeat evaluation queries.
- Recalculation updates individual rows and can amplify writes for larger
  carts.
- Checkout correctly uses bounded cart relations but holds a broad transaction
  while constructing customer, order, shipment, payment and dispatch side
  effects.
- Abandoned-cart scheduling scans indexed status and activity fields, but
  bundle-only carts are outside its item predicate.
- No cart size limit beyond per-line quantity was identified; regular and
  bundle line counts may grow.

A later implementation phase should establish query budgets with representative
regular, gift and bundle carts. This audit does not claim a measured production
latency profile.

## 25. Existing Test Coverage

| Test file | Area | Layer / engine | Covered behavior | Important missing edges |
| --- | --- | --- | --- | --- |
| `tests/Feature/CartCheckoutApiTest.php` | Regular cart and checkout | Backend feature / test DB | Add, update, remove, clear, price recalc, stock, order and authenticated association | Checkout cross-owner, status/expiry, idempotency, concurrent add, sale windows |
| `tests/Feature/ProductBundleTest.php` | Bundles | Backend feature / test DB | Bundle CRUD, options, inventory, checkout lines, promotion/loyalty and cross-session item mutation | Auth owner on create, duplicate identity, concurrent add, price windows |
| `tests/Feature/PromotionEngineTest.php` | Coupons/promotions/gifts | Backend feature / test DB | Rules, discounts, gifts, stacking, redemptions and loyalty | Concurrent limits, paid+gift collision, rollback |
| `tests/Feature/AbandonedCartRecoveryTest.php` | Recovery | Backend feature / test DB | Detection, reminders, unsubscribe, restore, expiry, converted suppression and scheduler | Replay, cross-user target, bundles/coupon/gifts, capability handling |
| `tests/Feature/PaymentApiTest.php` | Payment | Backend feature / test DB | Methods, provider states, amount, status, webhook signature/replay | Initiation ownership, idempotency and duplicate transactions |
| `tests/Feature/ShippingApiTest.php` | Shipping | Backend feature / test DB | Providers, methods, offices, calculation, checkout and validation | Cart ownership and real provider failure boundary |
| `tests/Feature/B2BPortalTest.php` | Cart quote | Backend feature / test DB | Authenticated cart quote happy path | Full ownership matrix, bundles and guest transition |
| `tests/Feature/MarketingPlatformTest.php` | Cart analytics | Backend feature / test DB | Server add-to-cart dispatch | Frontend/server deduplication and fallback events |

The current frontend suite has no identified cart-focused browser tests. Tests
for the read-only storefront often assert that commerce UI is absent, which is
consistent with current deployment routing but does not validate future cart
readiness.

Local feature tests normally use the repository test database configuration.
MySQL-specific race and constraint behavior requires CI-equivalent validation.

## 26. Confirmed Findings

The authoritative details, evidence, verification steps and acceptance criteria
are in `CART_GAP_REGISTER.json`.

| IDs | Theme |
| --- | --- |
| CART-001, CART-003, CART-017, CART-022 | Identity and ownership |
| CART-002, CART-008, CART-016, CART-020 | Checkout, payment and transaction boundary |
| CART-006, CART-007 | Price authority |
| CART-009, CART-015 | Promotion concurrency and gift identity |
| CART-010, CART-021, CART-025 | Recovery security and fidelity |
| CART-011, CART-012, CART-013, CART-014 | Lifecycle, eligibility, stock and mutation races |
| CART-004, CART-005, CART-018, CART-019, CART-026 | Frontend state, UX, analytics and success navigation |
| CART-023, CART-024 | Deployment gate and browser coverage |

No finding is fixed by this audit. Every finding remains `open`.

## 27. Open Questions

One register item is explicitly an open question:

- **CART-024:** no identified real-browser suite proves SSR, hydration, reload,
  multi-tab, offline, mobile or accessibility behavior.

Two risks remain `likely` pending controlled runtime evidence:

- **CART-016:** actual queue driver and any future real provider behavior must be
  instrumented to establish whether work escapes or extends the transaction.
- **CART-019:** configured analytics sinks must be observed to establish actual
  duplicate or phantom event behavior.

Other design decisions requiring approval:

- whether a user may own more than one active cart;
- merge precedence for quantities, bundles, coupons and gifts;
- cart price refresh and customer consent when totals change;
- whether equivalent bundles aggregate or remain separate;
- backorder rules by availability status;
- recovery merge versus replace behavior;
- customer profile versus immutable checkout snapshot ownership;
- stock reservation timing before a payment redirect.

## 28. Prioritized Remediation Plan

Priority order:

1. Close ownership and duplicate-order blockers.
2. Define authoritative price, lifecycle and eligibility contracts.
3. Make promotion, line mutation and recovery behavior concurrency-safe.
4. Replace frontend split-brain state with durable identity and explicit
   failures.
5. Establish checkout/provider/idempotency boundaries.
6. Add MySQL concurrency and browser acceptance coverage.

No remediation is authorized by this document. Each phase requires separate
review, implementation, tests and release approval.

## 29. Proposed Commerce Phase Sequence

### Commerce Phase 1B - Cart Backend Safety and Pricing

**Goal:** Establish one backend cart identity, ownership, lifecycle, pricing,
eligibility and recovery contract.

**Included findings:** CART-001, CART-003, CART-006, CART-007, CART-009,
CART-010, CART-011, CART-012, CART-013, CART-014, CART-015, CART-017,
CART-021, CART-022 and CART-025.

**Explicit exclusions:** storefront redesign, public route enablement, payment
provider rollout, checkout redesign, automatic commerce jobs and supplier
integration.

**Potential migrations:** bounded session identity strategy; user active-cart
constraint if approved; promotion redemption uniqueness; line identity changes
if required. Exact migrations require a separate design.

**Required tests:** ownership matrix, lifecycle rotation, sale windows,
eligibility transitions, MySQL concurrent line mutation, promotion limits,
gift collision, bundle/recovery fidelity and zero supplier/catalog-sync writes.

**Release gate:** no blocker/high backend finding in scope remains open; MySQL
CI passes; all cart endpoints use the approved identity service.

**Rollback:** additive schema must be backward compatible; behavior changes
need flaggable or deploy-reversible boundaries; never delete existing carts as
an upgrade shortcut.

### Commerce Phase 1C - Cart Storefront UX

**Goal:** Deliver one durable frontend cart source with explicit loading,
failure, quantity, currency, unavailable-line and accessibility behavior.

**Included findings:** CART-004, CART-005, CART-018, CART-019, CART-024 and
CART-026.

**Explicit exclusions:** checkout provider activation, order/payment redesign,
customer accounts, wishlist, compare and analytics expansion.

**Potential migrations:** none expected.

**Required tests:** Nuxt store/component tests and browser tests for SSR,
hydration, hard reload, new tab, multi-tab synchronization, API failure,
mobile, keyboard use, duplicate clicks and safe success navigation.

**Release gate:** rendered lines, count, subtotal and checkout payload share one
source; no customer data in success URLs; browser matrix passes.

**Rollback:** keep public route enablement separate from code deployment and
retain the existing routing-edge disable switch.

### Commerce Phase 1D - Checkout Readiness Audit and Gate

**Goal:** Make order creation, payment, shipping and stock transitions
authorized, idempotent and operationally recoverable.

**Included findings:** CART-002, CART-008, CART-016 and CART-020.

**Explicit exclusions:** public launch, new payment providers, new shipping
providers, customer-account expansion and ERP workflow redesign.

**Potential migrations:** checkout idempotency key and unique constraints;
payment request/provider identity uniqueness; customer identity changes only
after approved data migration design.

**Required tests:** concurrent checkout under MySQL, duplicate payment
initiation, provider timeout/failure, after-commit dispatch, stock contention,
rollback and authorized order retrieval.

**Release gate:** one logical checkout creates one order/payment attempt,
external calls occur outside locked transactions, ownership is consistent and
operational retry/rollback procedures are documented.

**Rollback:** idempotency records must remain readable across versions; provider
activation must be independently reversible; orders already created must never
be deleted by rollback.

### Later - Public Commerce Enablement

**Included finding:** CART-023.

Only after 1B, 1C and 1D gates pass should deployment routing expose cart and
checkout pages. Route enablement, monitoring, support procedures and smoke
tests require an explicit release phase.

### Commerce Phase 1B.1 - Unified Cart Identity and Ownership Boundary

Commerce Phase 1B is split into controlled subphases. Phase 1B.1 is merged,
deployed and staging verified. It introduced one request-level Cart resolver
for regular Cart,
bundle Cart, checkout, Cart quote, shipping and PC Builder add-to-Cart
operations.

The resolver accepts only canonical lowercase UUID values supplied through
`X-Cart-Session`. A missing or blank header creates a server-generated
anonymous Cart. A malformed non-empty value fails with a generic `422` before
Cart lookup or creation. Guest requests cannot use a user-owned Cart;
authenticated requests cannot use a Cart owned by another user. An
authenticated user may claim an anonymous Cart only inside a transaction after
locking and re-checking the Cart row.

This locally remediates CART-001 and CART-022. Checkout now resolves ownership
before any checkout mutation or side effect. Shipping resolves Cart subtotal
only through the shared session boundary; a supplied numeric `cart_id` is only
a consistency assertion and cannot authorize lookup by itself.

CART-017 is only partially remediated for session format and lookup safety.
Dedicated Cart and checkout throttling remains open. Phase 1B.1 did not merge
carts or select among multiple user carts; that separately approved behavior is
implemented locally in Phase 1B.2. Pricing, promotion concurrency, recovery
semantics and checkout idempotency remain unchanged and open. Public Cart and
checkout pages remain disabled pending the later release gates.

This subphase adds no migration, frontend production change, Product or stock
behavior change, supplier behavior, Catalog Sync behavior, Sync All, automatic
sync or UPDATE enablement.

### Commerce Phase 1B.2 - Cart Lifecycle and Guest-to-User Policy

Phase 1B.2 is merged, deployed and staging verified. An eligible active Cart now means
`status=active` with `expires_at` either null or later than the current time.
Lifecycle values are centralized as `active`, `converted`, `expired` and
`merged`. A successful resolution uses a 14-day sliding lifetime and renews
only when expiry is null or no more than seven days away.

Guest clients rotate expired, converted or merged anonymous sessions to a new
UUID. Authenticated clients converge every eligible active Cart they own to one
canonical Cart inside one transaction. Resolution locks the User first, then
locks discovered Cart rows in ascending ID order and revalidates ownership and
lifecycle state. A supplied eligible anonymous or same-user Cart is the target;
without one, the lowest-ID eligible user Cart is selected.

During login the supplied guest Cart remains canonical. Other eligible user
Carts merge into it without recalculating stored paid-line or bundle prices.
Paid quantities are summed with the retained target unit price, while a total
above 99 fails the entire transaction with a generic `409`. Distinct conflicting
coupon codes also fail with `409`. Existing gift rows are removed and the
existing automatic-gift evaluation runs once after the structural merge.
Bundle rows move without aggregation. Source Cart records remain historical
with `status=merged`, immediate expiry and no remaining item or bundle rows.

The manual `carts:expire-stale` command is preview-only by default. Its explicit
`--apply` mode marks only stale active Carts as expired in deterministic chunks.
It deletes no Cart or Cart content and is not scheduled.

This locally remediates CART-003 and CART-011 while preserving the Phase 1B.1
ownership and UUID boundaries. The original audit findings remain as historical
evidence, with additive progress recorded in the gap register. Cart pricing,
early stock feedback, recovery semantics, line-mutation concurrency, checkout
idempotency and retention cleanup remain open. Public Cart and checkout pages
remain disabled.

Phase 1B.2 adds no migration, frontend production change, Product or stock
behavior change, supplier behavior, Catalog Sync behavior, Sync All, automatic
sync or UPDATE enablement.

### Commerce Phase 1B.3 - Authoritative Cart Pricing and Price Refresh

Phase 1B.3 is merged, deployed and staging verified.
`Product::effectivePrice()` is the authoritative
EUR customer price for Product resources, regular Cart lines, bundle component
snapshots, bundle calculations, Cart-derived shipping and quote amounts,
checkout and Order snapshots. The existing date-aware sale contract ignores
future, expired, equal-to-regular and above-regular sale prices.

Cart reads refresh stored item and bundle prices only when cent values or
semantic component snapshots changed. Automatic gifts are reevaluated once
when paid pricing changed, not on every unchanged GET. Checkout performs the
same refresh before any Customer, Order, Shipment, Payment, stock, redemption,
event, job or email side effect. A customer-visible change commits the active
Cart for review and returns HTTP 409; a stable retry uses the refreshed Cart
state for Order snapshots.

This locally remediates CART-006 and CART-007 while preserving CART-001,
CART-003, CART-011, the UUID-validation part of CART-017 and CART-022. It adds
no automatic Cart repricing, migration, frontend production change, public
commerce route, Product or supplier mutation, Catalog Sync behavior, Sync All,
automatic sync or UPDATE enablement. Recovery, promotion concurrency and
checkout idempotency remain open.

### Commerce Phase 1B.4 - Cart Product Eligibility and Stock Feedback

Phase 1B.4 is merged, deployed and staging verified.
`Product::isPubliclyVisible()` remains the final
public-visibility authority. `AvailabilityStatusService::allowsPurchase()`
remains the purchase-permission authority, and
`AvailabilityStatusService::requiresStock()` remains the stock-tracking
authority.

Cart add and quantity-update operations validate the resulting quantity before
mutation. Stock-tracked Products use `max(0, Product.quantity)` and a maximum
of 99. Purchase-enabled non-stock-tracked Products, including preorder and
backorder states, are not capped by `Product.quantity`. `reserved_quantity` is
not subtracted because the existing final checkout enforcement does not use it
as available-stock authority.

Cart display now includes deterministic read-only readiness for regular and
bundle lines plus a Cart-level checkout-ready summary. Existing invalid lines
are flagged rather than deleted or clamped. Soft-deleted Products remain as
Cart Item identities with their stored price snapshot; deleted Product content
is not exposed. Bundle readiness evaluates active dates, option validity,
component eligibility and component quantity multiplied by Cart quantity.

Checkout preserves the Phase 1B.3 price-review result first, then rejects a
non-ready Cart before Customer, Order, Shipment, Payment, promotion-redemption,
stock, event, job or email side effects. Existing locked checkout stock
validation and reduction remain the final authority. Shipping and Cart-derived
quotes reject non-ready Carts, while PC Builder validates all components before
an atomic add.

This locally remediates CART-012 and CART-013 while preserving CART-001,
CART-003, CART-006, CART-007, CART-011, the UUID-validation part of CART-017 and
CART-022. It adds no stock reservation, mutation-concurrency guarantee,
idempotency redesign, automatic line deletion, migration, frontend production
change, public commerce route, Product or supplier mutation, Catalog Sync
behavior, Sync All, automatic sync or UPDATE enablement. CART-014 and CART-015
were left for the separately approved Phase 1B.5.

### Commerce Phase 1B.5 - Cart Item Mutation Concurrency and Gift-Line Integrity

Phase 1B.5 is merged, deployed and staging verified and remediates CART-014
and CART-015.
The Cart row is the serialization boundary for regular-line, bundle, coupon,
pricing and automatic-gift mutations. The lock order is Cart first, then
existing Cart and bundle rows by ascending ID. Recognized database concurrency
errors use bounded retries and a safe generic conflict after exhaustion.

The database identity is now `(cart_id, product_id, is_gift)`, permitting at
most one paid and one aggregated gift line for one Product. The migration
changes only this unique index and refuses rollback when paid/gift coexistence
would make the old identity destructive.

Automatic gifts are grouped by Product and summed across applicable actions.
The stored primary promotion source follows existing priority descending and ID
ascending order. Reconciliation keeps gifts zero-price, updates only changed
derived state, removes only stale gift rows and defers `gift_added` until commit
only for a new row. Direct gift PATCH and DELETE operations return a safe HTTP
409.

Promotion Product, Category, Brand, quantity-minimum, scoped percentage,
bundle-discount and buy-X-get-Y inputs include paid rows only. A gift cannot
qualify itself, recursively grow, or keep its source promotion alive after paid
eligibility is removed.

Merge removes source gifts and regenerates canonical target gifts. Recovery
preserves line type, PC Builder applies its regular-item batch under one Cart
boundary, and checkout keeps distinct paid and zero-price gift Order snapshots.
Final checkout stock enforcement includes the combined paid and gift demand.

This phase adds no stock reservation, checkout idempotency, promotion
usage/redemption concurrency redesign, frontend production change, public Cart
or checkout route, Product mutation during Cart editing, supplier change,
Catalog Sync behavior, Sync All, automatic sync or UPDATE enablement. CART-009
remained open after this phase and is remediated locally by Phase 1B.6.

### Commerce Phase 1B.6 - Promotion and Recovery Safety

Phase 1B.6 is merged, deployed and staging verified and remediates CART-009 and
CART-010.
Promotion rows are the concurrency boundary for usage consumption. Applied IDs
are sorted and locked in ascending order. Status, dates, coupon association,
rules, global usage, per-user usage and canonical Cart-session usage are
revalidated for every Promotion before the first write. The database enforces
one non-null Promotion/Order identity, duplicate Order reprocessing is
idempotent, multi-Promotion consumption is all-or-nothing, and rollback
restores redemption rows and usage counters together.

Checkout consumes the locked Promotions after the Order and line snapshots are
created but before shipment provider calls, payment initiation, stock
reduction, Cart clearing/conversion, reward redemption, jobs, email or
Order/recovery events. A concurrent eligibility change returns
`cart_promotion_changed` and rolls back the Customer, Order, Order lines and
Promotion state. `promotion_applied` is emitted only after commit.

Abandoned-Cart records now distinguish `restored` from `recovered`. Recovery
locks the record by token and revalidates status and expiry inside one
transaction. A successful restore writes `restored_at` and a unique
`restored_cart_id`; replay returns `cart_recovery_consumed`. Unknown, expired
and suppressed records share a generic unavailable response. The currently
authenticated Sanctum User is the only ownership authority. The token never
authenticates a request and the record User is never assigned to an anonymous
Cart.

A recovery target must be fresh or confirmed active, compatible and completely
empty. Paid items, gift items, bundles or a coupon make a Cart populated; such
a Cart is left unchanged and recovery returns a new session. The supported
paid/gift restore plan is checked before mutation, uses current paid Product
prices, keeps gifts zero-price, reconciles automatic gifts once and refreshes
pricing once. Restored records receive no more reminder email. Their
`restored_cart_id` follows authenticated Cart merge and later checkout changes
the record to `recovered` with the Order and revenue.

CART-021 remains open because token hashing and recovery URL changes are
outside this phase. CART-025 remains open because versioned bundle/coupon
snapshot fidelity was not added. Public Cart and checkout pages remain
disabled. No Product mutation during recovery, stock reservation, frontend
production change, supplier behavior, Catalog Sync behavior, Sync All,
automatic sync or UPDATE enablement was added.

### Commerce Phase 1C.1 - Persistent Cart Identity and Authoritative Frontend State

Phase 1C.1 is merged, deployed and staging verified and remediates CART-004 and
CART-005.
The frontend stores only a canonical Cart UUID in the request-scoped,
frontend-readable `mc_cart_session` Nuxt cookie. The cookie is shared by SSR
and hydration, and valid backend rotation from Cart-bearing responses is
persisted before consumers receive the response. Malformed persisted values
are cleared before requests. An `invalid_cart_session` Cart GET may retry once
after clearing the cookie; mutations are never replayed automatically.

The backend Cart response is the sole frontend Cart-content authority. Pinia
no longer has local fallback lines or a `backendAvailable` source switch.
Confirmed Cart contents remain visible after a failed mutation, while explicit
request status, safe errors and per-operation pending keys support retry and
duplicate-click protection. Cart count, subtotal, lines, bundles, gifts,
coupon and readiness all derive from the same response.

Login preserves the guest capability and sends it with authenticated headers,
allowing the existing backend policy to claim, merge or rotate the Cart.
Logout and User switching retain the canonical capability but invalidate stale
rendered Cart state; an authority epoch prevents an earlier in-flight response
from repopulating it. No quantities, coupons or gifts are merged in the
browser.

Add/remove analytics run only after confirmed backend mutation and use returned
Cart line prices and currency. CART-018 remains partially open for the complete
loading/error/accessibility UX matrix. CART-019 remains partially open because
general cross-layer analytics deduplication was not added. CART-024 remains
open for real-browser SSR, reload, new-tab and accessibility coverage.
CART-026 remains open. Public Cart and checkout pages remain disabled, and no
backend Cart, checkout, Product, stock, supplier or Catalog Sync behavior
changed.

### Commerce Phase 1C.2 - Cart UX States and Analytics Consistency

Phase 1C.2 is merged, deployed and staging verified and remediates CART-018
and CART-019.
The Cart page and drawer distinguish unresolved, loading, confirmed empty,
confirmed ready, mutating, failed, readiness-blocked and review-required
states. An initial failure offers an explicit idempotent Cart GET retry. A
failed mutation preserves the last confirmed Cart and exposes a scoped
Bulgarian error; important ownership, readiness, price, Promotion and mutation
conflicts also receive a compact Cart-level summary.

Quantity changes use a separate draft and explicit submit. The authoritative
quantity changes only after an accepted backend response. Scoped operation keys
prevent duplicate Product, bundle, coupon, remove and clear requests while
allowing unrelated controls to remain independent. Coupon and bundle controls
surface their own pending and failure states. Gifts show zero price and have no
editable quantity or remove controls. Readiness issues are mapped to actionable
Bulgarian messages, and stock maximums are displayed only when explicitly
provided by backend readiness.

`useCartAnalytics` centralizes frontend Cart events. Each accepted frontend
operation has an in-memory identity, and confirmed pre/post Cart comparison
provides Product or bundle identity, quantity delta, public SKU, authoritative
line price and returned currency. Failed, stale, superseded and auth-transition
responses produce no success analytics. Quantity changes reuse the existing
`add_to_cart`/`remove_from_cart` delta contract. Clear Cart emits no synthetic
remove cascade, no `view_cart` event was introduced, and `begin_checkout`
requires an explicit ready checkout request that succeeds. Payloads omit Cart
session UUIDs, recovery tokens, Supplier data and internal Product costs.

CART-024 is remediated locally by Phase 1C.3. CART-026 remains open for
checkout-success URL data safety. Public Cart and checkout remain disabled. No
backend Cart, checkout, payment, Product, stock, Supplier or Catalog Sync
behavior changed.

### Commerce Phase 1C.3 - Real Browser Cart Lifecycle Acceptance

Phase 1C.3 is merged, deployed and staging verified and remediates CART-024. Playwright is
the approved real-browser framework and is included only as a frontend
development dependency. A deterministic local Cart API fixture mirrors the
approved response envelope while Laravel tests remain the authority for
backend Cart behavior.

Chromium desktop covers SSR and hydration, persistent Cart identity, hard
reload, new-tab and browser-restart behavior, separate-context isolation,
malformed-cookie recovery, expired and converted session rotation, multi-tab
convergence, offline failure and explicit retry, authenticated convergence,
logout and User switching, stale-response suppression, Product, quantity,
remove, clear, coupon and bundle mutations, gifts, readiness and conflict
feedback, exactly-once analytics, keyboard navigation and drawer focus.
WebKit mobile covers responsive Cart layout, reachable controls and
horizontal-overflow protection.

The suite uses only local Nuxt and fixture API services. It accesses no live
environment and creates no real checkout, Order or payment. CART-023 remains
open because public Cart and checkout routes remain disabled at Nginx.
CART-026 remains open for checkout-success URL data safety. No backend Cart,
Product, stock, Supplier or Catalog Sync behavior changed.

### Commerce Phase 1C.4 - Checkout Success Data Safety

Phase 1C.4 is merged, deployed and staging verified. CART-026 is remediated.
Successful checkout navigates only to `/checkout/success`; Order,
customer, total, payment and capability data are not serialized into the URL.
Checkout creates one 32-byte opaque confirmation capability inside the
existing transaction, stores only its SHA-256 hash, and sends the plaintext
capability only through a short-lived HttpOnly, SameSite Lax cookie.

Issued and deletion cookies are constructed explicitly with Symfony Cookie
`domain: null`. Staging verification confirmed that the dedicated cookie is
host-only and preserves HttpOnly, Secure, SameSite Lax and Path `/`. Global
Laravel and admin session-cookie behavior is unchanged.

The dedicated confirmation endpoint is cookie-only, rate limited and
no-store. It returns minimal trusted Order/payment presentation with a masked
email. Missing, malformed, unknown, expired and deleted-Order capabilities
share one generic response. Nuxt forwards the cookie during SSR, includes
credentials in browser requests, ignores query authority, safely canonicalizes
query/hash URLs, and emits purchase analytics only from trusted confirmation
data. The page is noindex/nofollow/noarchive and no-referrer.

### Commerce Phase 1D.1 - Checkout Idempotency and Atomic Cart Conversion

Phase 1D.1 is merged, MySQL 8.4 CI verified, deployed and staging
schema/release-gate verified. It remediates CART-002. Checkout
requires a 43-character Base64URL key generated from 32 cryptographically
secure bytes. The database stores only the key's SHA-256 hash and a keyed
HMAC-SHA-256 fingerprint of recursively canonicalized validated checkout data;
no customer PII or raw request/response is persisted in that record.

A unique canonical idempotency row per Cart is created after side-effect-free
validation and before checkout writes. It is completed with the Order inside
the same transaction as payment transaction, shipment, Promotion and loyalty
effects, stock reduction, Cart conversion and confirmation capability.
Rollback removes the entire attempt. Approved jobs, email and Order events are
dispatched after commit only once.

Completed replay lookup runs before normal converted-Cart rotation and remains
independently authorized by Cart owner or original guest session. Equivalent
same-key or different-key requests return the original trusted Order values
without repeating checkout effects. Changed data receives a generic conflict.
Each successful replay issues a fresh hash-only confirmation capability while
other unexpired capabilities remain valid; the host-only cookie contract and
120-minute lifetime remain unchanged.

The Nuxt client keeps one Web Crypto key in checkout-page memory only. It
retains the key after ambiguous network/server failure for an explicit retry,
clears it for definitive conflicts and validation failures, and clears it when
form or Cart identity/content changes or checkout succeeds. It adds no
automatic retry and sends no idempotency identity to URLs, storage or
analytics. Focused Laravel, MySQL process-concurrency, frontend and Playwright
tests cover duplicate submission, response loss, replay, conflict and rollback.

CART-008 remained open for payment-initiation authorization and provider-level
idempotency after Phase 1D.1. CART-023 remains open because Nginx still
disables public Cart and checkout routes. Broader browser/MySQL acceptance
remains assigned to Phase 1D.3. No Product, stock-policy, Supplier or Catalog
Sync behavior changed.

### Commerce Phase 1D.2A - Payment Launch Policy, Card Gate and Public Initiation Lockdown

Phase 1D.2A is merged, CI verified, deployed and staging verified. Initial
launch keeps card disabled through
`PAYMENT_CARD_ENABLED=false`. Database status cannot bypass this launch policy,
and an allowlisted, container-resolved provider must independently report
operational readiness. The production card provider reports non-operational,
throws safely on direct initiation, collects no cardholder data, creates no
redirect and performs no external request. A test-only fake validates the
provider boundary without selecting a gateway.

Payment-method discovery, checkout acceptance and `PaymentService` share one
fail-closed availability authority. Card is omitted from the public method
collection and rejected before Cart resolution, idempotency persistence or any
checkout effect. The unauthenticated `POST /api/v1/payments/initiate` route,
controller action, request and frontend client are removed. Checkout remains
the only public creator of an initial payment transaction and preserves its
atomic replay contract.

Cash on delivery and bank transfer remain available when active. Leasing and
webhook behavior are unchanged; the leasing placeholder URL remains a separate
known issue. At completion of 1D.2A, CART-008 was partially remediated and its
remaining authenticated re-initiation, attempt idempotency, concurrency,
provider identity and retry lifecycle were assigned to Phase 1D.2B. That work
is now complete locally. CART-023 remains open because Nginx continues to
disable public Cart and checkout routes.

### Commerce Phase 1D.2B - Order-owned Payment Re-initiation and Idempotency

Phase 1D.2B is complete locally only. The authenticated endpoint requires
direct Order ownership; a separate guest endpoint resolves only a dedicated,
hash-only retry capability. Both accept an empty body and require a 32-byte
Base64URL idempotency key whose plaintext is never stored.

The transactional coordinator locks the Order, rechecks authorization and
serializes attempt/transaction decisions. Pending or authorized payments are
returned without provider invocation. Failed or cancelled payments may create
one explicit new attempt only for an available operational online method.
Paid, refunded, missing, offline and leasing states fail closed. A stable
provider identity is derived in memory and is never persisted or returned.

The production card provider remains non-operational and card remains disabled
by default. There is no external request, automatic retry, payment polling,
webhook redesign, ERP sync or public commerce activation. CART-008 is
remediated locally. CART-023 remains open. See
[Order-owned Payment Retry](PAYMENT_RETRY.md).

### Commerce Leasing Phase A - Manual Leasing Application Module

Commerce Leasing Phase A is merged, MySQL CI verified, deployed and staging
schema/safety verified. It does not release public
Cart or checkout routes. Leasing is absent from payment discovery and rejected
by checkout unless the independent `PAYMENT_LEASING_ENABLED` flag is explicitly
enabled; database activation alone is insufficient.

The enabled path remains inside atomic idempotent checkout. It validates an
allowlisted term, down payment, contact preference, optional plain-text note and
explicit consent. The trusted server-side Order total bounds the down payment.
One Order has at most one leasing application, and replay does not duplicate
the application, immutable submitted activity or after-commit event.

The workflow is manual. It records no EGN, identity document, employment,
income, bank, card or provider data. The local provider performs no network
request, returns no redirect or provider transaction ID and makes no approval
decision. Authorized Filament staff review list/view pages, assign an eligible
Order manager, follow allowlisted status transitions and append notes. There is
no admin create/edit/delete/bulk-delete surface.

Queued customer, internal-email and authorized-admin notifications run after
the checkout commit and acknowledge receipt without promising approval or
terms. Provider integration, retries, webhook verification and automatic
decisioning require a separate reviewed phase.

CART-008 is remediated, deployed and staging verified by Phase 1D.2B, which is
merged, MySQL CI verified and staging schema/security verified. CART-023
remains open because Nginx continues to disable public Cart and checkout routes. See
[Manual Leasing Applications](MANUAL_LEASING_APPLICATION.md).

Commerce Phase 1D.3 is complete locally only. It adds a server-authoritative
payment presentation, explicit safe checkout-success actions and a
machine-readable acceptance matrix. It does not enable public routes, card,
leasing, a real provider, automatic retry or automatic redirect. See
[Commerce Checkout and Payment Acceptance](COMMERCE_CHECKOUT_PAYMENT_ACCEPTANCE.md).

## 30. Release Gates

Commerce Phase 1A is complete only as a local, read-only audit. It authorizes no
runtime change.

Before any subsequent Phase 1B subphase starts:

- this report and the gap register are reviewed and approved;
- ownership and guest-transition policy decisions are recorded;
- no finding is silently reclassified as fixed.

Before any public cart route is enabled:

- CART-001 and CART-002 are closed with regression tests;
- all high findings have approved resolution or explicit risk acceptance;
- backend and frontend use one cart identity and one price authority;
- MySQL concurrency validation passes;
- real browser SSR, persistence, multi-tab, mobile and accessibility tests pass;
- checkout, payment and shipping failure/rollback drills pass;
- privacy review approves recovery and success-page handling;
- public route ownership and rollback are documented;
- required CI is green and an explicit deployment approval is given.

Standing safety boundaries:

- no supplier data is used as direct cart price authority;
- no `supplier_products` write belongs to cart work;
- no Catalog Sync behavior changes;
- no Sync All or automatic sync;
- no UPDATE sync enablement;
- no Product content, image, category or attribute overwrite;
- no deployment from an unmerged feature branch.
