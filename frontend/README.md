# mycomputer.bg v2 Frontend

Nuxt 4 storefront for the Laravel API backend.

## Stack

- Nuxt 4
- Vue 3
- TypeScript
- Tailwind CSS
- Pinia
- Nuxt Image
- SSR enabled

## Install

```bash
cd frontend
npm ci
cp .env.example .env
```

If `package-lock.json` is not present yet, run `npm install` once on a developer machine, commit the generated lockfile, then use `npm ci` for repeatable installs.

## Environment

```env
NUXT_PUBLIC_API_BASE_URL=http://localhost:8000/api/v1
NUXT_PUBLIC_SITE_URL=http://localhost:3000
NUXT_PUBLIC_GA4_ID=
NUXT_PUBLIC_META_PIXEL_ID=
```

The Laravel API must be running and reachable at `NUXT_PUBLIC_API_BASE_URL`.

## Development

```bash
npm run dev
```

Default Nuxt URL:

```text
http://localhost:3000
```

## Build

```bash
npm run build
npm run preview
```

Staging preview:

```bash
npm run preview -- --host 0.0.0.0 --port 3000
```

## Implemented

- Homepage using `/api/v1/home`
- Category listing pages with filters, sorting and pagination
- Brand pages
- Product detail page with gallery, attributes, related and accessory products
- Search page
- Compare page using `/api/v1/compare`
- Cart with backend API integration and local fallback
- Checkout page with customer, billing, delivery, shipping and payment sections
- Checkout success page
- Authentication pages: login, register and forgot password
- Account area: dashboard, profile, addresses, orders and security
- Pinia stores for cart, compare, UI and authentication
- Shipping provider/method/office UI components
- Payment method and payment instruction UI components
- Static pages
- SEO meta helpers and JSON-LD product schema
- Reusable catalog, product, cart and UI components
- Wishlist, reviews, blog/content, B2B account and quote screens
- Product bundles, PC Builder, AI assistant and abandoned cart recovery screens

## Authentication

The frontend uses `useAuthStore` with Sanctum bearer tokens from the Laravel API.

Generated routes:

- `/login`
- `/register`
- `/forgot-password`
- `/account`
- `/account/profile`
- `/account/addresses`
- `/account/orders`
- `/account/security`

Generated account components:

- `LoginForm`
- `RegisterForm`
- `ForgotPasswordForm`
- `ProfileForm`
- `AddressBook`
- `AddressForm`
- `OrderHistoryTable`
- `AccountSidebar`

The token is stored in `localStorage` by the current implementation and attached to API calls through `useApi()`.
For production hardening, migrate customer authentication to an HttpOnly cookie flow when the backend contract is ready.

## Backend Dependency

The Laravel backend must expose:

- `/api/v1/auth/*`
- `/api/v1/account/*`
- `/api/v1/cart/*`
- `/api/v1/checkout`
- `/api/v1/shipping/*`
- `/api/v1/payments/*`
- catalog/search/homepage endpoints
- `/api/v1/wishlist*`
- `/api/v1/reviews*`
- `/api/v1/blog*`
- `/api/v1/b2b*`
- `/api/v1/bundles*`
- `/api/v1/pc-builder*`
- `/api/v1/ai*`

## Staging QA

Before staging approval, run:

```bash
npm ci
npm run build
npm run preview -- --host 0.0.0.0 --port 3000
```

Then browser-check homepage, category, product, cart, checkout, account, wishlist, reviews, blog, B2B, bundles, PC Builder and assistant routes against the staging Laravel API.

## Remaining

- Real leasing calculator
- Customer order detail page UI
- Email verification UI
- Real payment provider redirects/webhooks in the storefront
- HttpOnly cookie authentication migration
