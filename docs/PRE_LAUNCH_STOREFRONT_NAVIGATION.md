# Pre-Launch Storefront Navigation

## Status

Pre-Launch Storefront Navigation Cleanup is complete locally only.

The preceding Legal Content Finalization and Explicit Approval phase is merged,
CI verified, deployed and staging verified. The committed legal manifest is
approved. Runtime `LEGAL_CONTENT_APPROVED=false`, public commerce is disabled,
and CART-023 remains open.

This phase changes presentation only. It adds no route, redirect, migration,
database write, Nginx rule, runtime flag, account feature or commerce feature.

## Authority

The public route contract is repository-owned and deterministic:

- `deploy/nginx/mycomputer.conf.template` defines edge availability.
- `useCommerceReleaseGate()` remains the sole frontend commerce-state
  authority.
- `frontend/app/utils/storefrontRouteAvailability.ts` normalizes routes and
  defines which counterparts may be rendered.
- no browser HTTP probe, `window` check, delayed client-only hiding or CSS-only
  hiding decides link visibility.

Normalization removes trailing slashes, query strings and fragments before
classification, recognizes the `/en` prefix, and supports exact `/c/{slug}` and
`/p/{slug}` routes. External, `mailto:`, `tel:` and fragment targets are not
classified as blocked internal routes.

## Navigation Matrix

The state columns describe visible global or contextual navigation, not merely
whether a Nuxt source page exists.

| Route | Source | BG | EN | Closed | Confirmation-only | Open | Reason and evidence |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `/` | Header logo, homepage | yes | `/en` | visible | visible | visible | Explicit Nginx storefront entry; unit and browser audit. |
| `/catalog` | Header, mobile, footer, search, homepage | yes | `/en/catalog` | visible | visible | visible | Explicit Nginx route; search uses `?search=` on this route. |
| `/categories` | Header, mobile, footer, homepage | yes | `/en/categories` | visible | visible | visible | Explicit Nginx route; browser audit. |
| `/c/{slug}` | Category cards and breadcrumbs | yes | `/en/c/{slug}` | contextual | contextual | contextual | Explicit dynamic Nginx route; normalization and browser fixture tests. |
| `/p/{slug}` | Product cards | yes | `/en/p/{slug}` | contextual | contextual | contextual | Explicit dynamic Nginx route; normalization and browser fixture tests. |
| `/obshti-usloviya` | Footer | yes | no | visible | visible | visible | Approved BG-only legal route; EN counterpart is intentionally not rendered. |
| `/politika-za-poveritelnost` | Footer | yes | no | visible | visible | visible | Approved BG-only legal route; EN counterpart is intentionally not rendered. |
| `/cart` | Header button, mobile menu | gated | no | hidden | hidden | visible | Existing `canStartCheckout`; no duplicated state logic. |
| `/checkout` | Cart flow only | gated | no | no global link | no global link | no global link | Reached only through the approved Cart flow. |
| `/checkout/success` | Order completion only | gated | no | no global link | no global link | no global link | Existing confirmation capability only; never global navigation. |
| `/login`, `/register` | none | no | no | hidden | hidden | hidden | Edge-blocked pre-launch. |
| `/account`, `/account/*` | none | no | no | hidden | hidden | hidden | Edge-blocked pre-launch. |
| `/wishlist`, `/compare` | none | no | no | hidden | hidden | hidden | Edge-blocked pre-launch. |
| `/forgot-password`, `/reset-password` | none | no | no | hidden | hidden | hidden | Edge-blocked customer auth. Filament admin reset is unaffected. |
| `/search` | none | no | no | hidden | hidden | hidden | Edge does not publish the route; search submits to `/catalog?search=`. |
| `/about`, `/contacts`, `/delivery` | none | no | no | hidden | hidden | hidden | Nuxt source alone is not public edge availability. |
| `/warranty`, `/leasing`, `/service` | none | no | no | hidden | hidden | hidden | Nuxt source alone is not public edge availability. |
| `/blog`, `/bundles`, `/brand/*` | none on public entry pages | no | no | hidden | hidden | hidden | Edge-blocked; CMS CTA and bundle entry points are suppressed. |
| `/en/obshti-usloviya`, `/en/politika-za-poveritelnost` | none | no | no | hidden | hidden | hidden | Explicit Nginx 404 until separate translation and approval. |
| `/en/cart`, `/en/checkout` | none | no | no | hidden | hidden | hidden | Explicit Nginx 404 in every state. |

## Shared Surfaces

- `AppHeader.vue`: logo, Products, Categories, catalog search, route-aware
  locale switch, and release-gated Cart only.
- `MobileMenu.vue`: the same Products, Categories and release-gated Cart
  decisions as desktop.
- `AppFooter.vue`: Products, Categories and the two approved Bulgarian legal
  links; newsletter behavior is unchanged.
- `LanguageSwitcher.vue`: emits only real target-locale counterparts during
  SSR and omits its `<nav>` when fewer than two locales are available.
- `SearchBar.vue` and `SearchSuggestions.vue`: submit to the published,
  locale-aware catalog route.
- homepage fallback: links only to catalog/category routes; blocked delivery
  and blog entry points are absent.
- CMS presentation: internal CTA links render only when allowed by the central
  contract; bundle blocks are hidden while bundle routes remain unpublished.

Internal account, wishlist, compare, bundle and Checkout implementations are
not deleted. They simply receive no invalid public navigation entry point.

## Test Evidence

Focused unit coverage verifies:

- route normalization;
- BG-only legal counterparts;
- valid BG/EN catalog counterparts;
- closed, confirmation-only and open commerce semantics;
- blocked pre-launch paths;
- external and mail targets;
- shared desktop/mobile/footer contract use;
- SSR-safe locale switching with no homepage fallback or empty locale nav.

Deterministic Playwright coverage visits home, catalog, categories, one
category, one Product and both legal pages. It audits visible same-origin
anchors, legal locale behavior, desktop/mobile parity, keyboard focus, mobile
overflow, console errors and hydration warnings. Closed and
confirmation-only fixtures keep Cart hidden; the approved open fixture keeps
the existing Cart entry point.

The tests do not contact staging, crawl external sites, submit forms, create an
Order or mutate application data.

## Safety State

- Approved Terms and Privacy sources, manifest and approval audit are
  byte-identical.
- `LEGAL_CONTENT_APPROVED=false`.
- `PUBLIC_COMMERCE_ENABLED=false`.
- `PUBLIC_COMMERCE_CONFIRMATION_ENABLED=false`.
- `ABANDONED_CART_RECOVERY_ENABLED=false`.
- card and leasing remain disabled.
- CART-023 remains open.
- Catalog Sync defaults and behavior are unchanged.
- no Sync All or automatic sync is added.
