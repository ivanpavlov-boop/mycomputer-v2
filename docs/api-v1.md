# API v1

Base prefix: `/api/v1`

The public API is built for the Nuxt storefront. It returns only active, published products and never exposes supplier internals, raw XML/CSV payloads, `purchase_price`, or `source_payload`.

## Admin Supplier Import Monitoring

Authenticated admin endpoints:

- `GET /api/v1/admin/suppliers/import-runs`
- `GET /api/v1/admin/suppliers/{supplier}/import-runs`
- `POST /api/v1/admin/suppliers/{supplier}/run-import`
- `POST /api/v1/admin/suppliers/{supplier}/force-run-import`

Permissions:

- `view supplier import logs` for import run lists.
- `run supplier imports` for normal manual imports.
- `force supplier imports` for force imports.

Import run responses include supplier, feed, trigger type, status, metrics, warnings, errors and generated report data. They intentionally do not expose feed credentials or raw supplier payloads.

## B2B Portal

Authenticated endpoints:

- `GET /api/v1/b2b/status`
- `POST /api/v1/b2b/apply`
- `GET /api/v1/account/b2b/company`
- `PATCH /api/v1/account/b2b/company`
- `GET /api/v1/account/b2b/users`
- `POST /api/v1/account/b2b/users/invite`

Apply request:

```json
{
  "company_name": "Example Ltd",
  "vat_number": "BG123456789",
  "mol": "Ivan Petrov",
  "email": "office@example.com",
  "phone": "0888123456",
  "billing_address": "Sofia",
  "shipping_address": "Sofia"
}
```

Company applications are created with `approval_status = pending` and the current user is attached as `owner`.

## Quote Requests

Authenticated endpoints:

- `GET /api/v1/account/quotes`
- `POST /api/v1/account/quotes`
- `GET /api/v1/account/quotes/{quote}`
- `PATCH /api/v1/account/quotes/{quote}`
- `POST /api/v1/account/quotes/{quote}/submit`
- `POST /api/v1/account/quotes/{quote}/accept`
- `POST /api/v1/account/quotes/{quote}/messages`
- `POST /api/v1/account/quotes/{quote}/files`
- `POST /api/v1/cart/request-quote`
- `POST /api/v1/products/{slug}/request-quote`

Create quote request:

```json
{
  "notes": "Need bulk pricing",
  "items": [
    {
      "product_id": 1,
      "quantity": 10,
      "requested_price": 900,
      "notes": "Corporate purchase"
    }
  ]
}
```

Security rules:

- Customers can access only their own quotes or quotes for their active company.
- Customers cannot set `offered_price`.
- Quote acceptance is allowed only for `offered` quotes with a non-expired `valid_until`.
- Accepted quotes create orders with offered prices and enqueue ERP order sync.
- Quote files are validated by MIME type and size.

## Endpoints

### Health

`GET /api/v1/health`

Returns status, API version and timestamp.

### Homepage

`GET /api/v1/home`

Returns hero banner placeholders, featured categories, featured products, new products, bestsellers, promotional products and article placeholders.

### Navigation

`GET /api/v1/navigation/categories`

Returns active nested category navigation with `slug`, `name`, `icon`, `image` and `sort_order`.

### Authentication

Public endpoints:

`POST /api/v1/auth/register`

```json
{
  "first_name": "Ivan",
  "last_name": "Petrov",
  "email": "ivan@example.com",
  "phone": "0888123456",
  "company_name": null,
  "vat_number": null,
  "password": "Password1",
  "password_confirmation": "Password1"
}
```

Returns a Sanctum bearer token, user profile and roles.

`POST /api/v1/auth/login`

```json
{
  "email": "ivan@example.com",
  "password": "Password1"
}
```

Inactive users receive validation errors and cannot log in.

`POST /api/v1/auth/forgot-password`

`POST /api/v1/auth/reset-password`

Authenticated endpoints require:

```http
Authorization: Bearer {token}
```

- `POST /api/v1/auth/logout`
- `GET /api/v1/auth/me`
- `PATCH /api/v1/auth/profile`
- `PATCH /api/v1/auth/password`
- `GET /api/v1/auth/addresses`
- `POST /api/v1/auth/addresses`
- `PATCH /api/v1/auth/addresses/{id}`
- `DELETE /api/v1/auth/addresses/{id}`

Address body:

```json
{
  "type": "shipping",
  "first_name": "Ivan",
  "last_name": "Petrov",
  "phone": "0888123456",
  "country": "Bulgaria",
  "city": "Sofia",
  "postcode": "1000",
  "address_line_1": "bul. Bulgaria 1",
  "address_line_2": null,
  "company_name": null,
  "vat_number": null,
  "is_default": true
}
```

### Account

`GET /api/v1/account`

Returns profile, addresses, order summary and wishlist placeholder.

`GET /api/v1/account/orders`

Returns only orders owned by the authenticated user email.

`GET /api/v1/account/orders/{id}`

Returns one owned order with items, shipments and payment transactions. Orders belonging to another user return `404`.

Service portal endpoints require Sanctum authentication:

- `GET /api/v1/account/service`
- `POST /api/v1/account/service`
- `GET /api/v1/account/service/{ticket}`
- `POST /api/v1/account/service/{ticket}/messages`
- `POST /api/v1/account/service/{ticket}/files`
- `POST /api/v1/account/service/{ticket}/close`
- `GET /api/v1/account/service/order-products/{order}`

Service ticket create request:

```json
{
  "ticket_type": "warranty_claim",
  "order_id": 1,
  "product_id": 10,
  "subject": "Laptop warranty claim",
  "description": "Display flickering",
  "serial_number": "SN123"
}
```

Customer responses never include internal support notes or private file paths. File uploads accept `jpg`, `png`, `webp` and `pdf` up to 5 MB.

### Categories

`GET /api/v1/categories`

Query params: `parent_id`, `active`, `search`.

`GET /api/v1/categories/{slug}`

Returns category details, children and SEO fields.

`GET /api/v1/categories/{slug}/products`

Query params: `page`, `per_page`, `brand`, `price_min`, `price_max`, `stock_status`, `attributes[]`, `sort`, `search`.

`attributes[]` accepts canonical attribute codes such as `ram` and canonical value slugs such as `16_gb`. Legacy attribute/value slugs are still accepted for older catalog assignments.

### Brands

`GET /api/v1/brands`

Query params: `active`, `search`.

`GET /api/v1/brands/{slug}`

`GET /api/v1/brands/{slug}/products`

Supports the same product list query params.

### Products

`GET /api/v1/products`

Query params: `page`, `per_page`, `category`, `brand`, `price_min`, `price_max`, `stock_status`, `attributes[]`, `featured`, `new_product`, `bestseller`, `search`, `sort`.

Sort values: `relevance`, `price_asc`, `price_desc`, `newest`, `bestseller`, `featured`, `name_asc`, `name_desc`.

Attribute filters are canonicalized by the backend. Example: `attributes[]=16_gb` returns products with canonical value `16 GB`, even if the original supplier feed sent `16GB`, `16 GB`, `16 РіР±` or `16384 MB`.

`GET /api/v1/products/{slug}`

Returns product details, category, brand, images, grouped attributes, related products, accessories, SEO and structured data fields.

`GET /api/v1/products/{slug}/related`

`GET /api/v1/products/{slug}/accessories`

### Product Bundles

`GET /api/v1/bundles`

Query params: `type`, `page`, `per_page`.

`GET /api/v1/bundles/{slug}`

Returns active bundle data, fixed items, configurable options, price, original item price, savings and SEO fields.

`GET /api/v1/products/{slug}/bundles`

Returns active bundles that include the product as a fixed item or configurable option.

Example response:

```json
{
  "data": {
    "id": 1,
    "name": "Keyboard and Mouse Pack",
    "slug": "keyboard-and-mouse-pack",
    "type": "fixed_bundle",
    "pricing_type": "fixed_price",
    "original_price": 200,
    "price": 169,
    "savings": 31,
    "items": [],
    "options": [],
    "seo": {
      "meta_title": "Keyboard and Mouse Pack",
      "meta_description": null
    }
  }
}
```

### Search

`GET /api/v1/search`

Query params: `q`, `category`, `brand`, `price_min`, `price_max`, `stock_status`, `attributes[]`, `sort`, `page`, `per_page`.

Returns products, separate product bundle results, `total`, `bundle_total`, `page`, `per_page`, categories, brands, suggestions, `available_filters` and engine metadata.

Search is routed through `SearchServiceInterface`. Production can use Laravel Scout with Meilisearch by setting `SCOUT_DRIVER=meilisearch`; local/test environments may use the database fallback.

Supported search behavior:

- full text product search
- active product bundle search
- SKU, EAN and MPN search
- brand and category search
- bundle name, slug, description, type, pricing, included products and included brands
- autocomplete suggestions
- typo-tolerant behavior through Meilisearch, with common local fallback corrections
- filters for category, brand, stock, flags, price range and attributes
- canonical attribute filters and facets generated from `canonical_attributes` and `canonical_attribute_values`

Example:

```text
/api/v1/search?q=lenovo%20loq&brand=lenovo&price_max=2500&sort=relevance
```

`GET /api/v1/search/suggestions`

Query params: `q`.

Returns product autocomplete suggestions for search boxes.

Example response:

```json
{
  "data": {
    "products": {"data": []},
    "bundles": {
      "data": [
        {
          "id": 1,
          "name": "Lenovo Office Starter Pack",
          "slug": "lenovo-office-starter-pack",
          "type": "starter_pack",
          "price": 299,
          "savings": 30
        }
      ]
    },
    "bundle_total": 1,
    "suggestions": [
      "Lenovo ThinkPad E16 Gen 2",
      "Lenovo Office Starter Pack",
      "MC-LAP-001",
      "lenovo"
    ]
  }
}
```

### Filters

`GET /api/v1/filters/categories/{slug}`

Returns brands with counts, price range, stock statuses and filterable attributes with value counts.

Brand filter rows include `active` and `products_count`. Attribute values include `products_count`.

### Compare

`POST /api/v1/compare`

Body:

```json
{
  "product_ids": [1, 2]
}
```

Returns products, shared attributes, differences, prices and stock statuses.

### Cart

Guest carts use the `X-Cart-Session` header. If no session is supplied, the API creates one and returns `cart_session_id`.

`GET /api/v1/cart`

Returns the current cart, items, subtotal and session id.

`POST /api/v1/cart/items`

```json
{
  "product_id": 1,
  "quantity": 2
}
```

`PATCH /api/v1/cart/items/{id}`

```json
{
  "quantity": 3
}
```

`DELETE /api/v1/cart/items/{id}`

`DELETE /api/v1/cart`

Clears all cart items.

`POST /api/v1/cart/bundles`

Adds a product bundle to the cart.

```json
{
  "bundle_id": 1,
  "quantity": 1,
  "selected_items": [
    {"component_group": "mouse", "product_id": 25}
  ]
}
```

`PATCH /api/v1/cart/bundles/{id}`

Updates bundle quantity and configurable selections.

`DELETE /api/v1/cart/bundles/{id}`

Removes a bundle line from the cart.

`POST /api/v1/cart/email`

Captures guest email for abandoned cart recovery.

Headers:

- `X-Cart-Session: {cart_session_id}`

Payload:

```json
{
  "email": "client@example.com"
}
```

`POST /api/v1/cart/recover/{token}`

Restores a cart from a secure abandoned cart recovery token. The token does not expose cart IDs and expires according to `ABANDONED_CART_RECOVERY_TOKEN_DAYS`.

Optional headers:

- `X-Cart-Session: {cart_session_id}`

Returns the restored cart.

`POST /api/v1/cart/coupon`

Applies a promotion coupon to the current cart.

Headers:

- `X-Cart-Session: {cart_session_id}`

Payload:

```json
{
  "code": "WELCOME10"
}
```

`DELETE /api/v1/cart/coupon`

Removes the current coupon and any gift items created by that coupon.

Cart responses include promotion fields:

```json
{
  "coupon_code": "WELCOME10",
  "applied_promotions": [
    {
      "id": 1,
      "name": "Welcome 10",
      "code": "WELCOME10",
      "type": "percentage_discount",
      "discount": 10,
      "shipping_discount": 0
    }
  ],
  "promotion_discount_total": 10,
  "shipping_discount": 0,
  "gift_products": []
}
```

### Checkout

`POST /api/v1/checkout`

Requires `X-Cart-Session`.

```json
{
  "first_name": "Ivan",
  "last_name": "Petrov",
  "email": "client@example.com",
  "phone": "0888123456",
  "company_name": null,
  "vat_number": null,
  "billing_address": "Sofia, Bulgaria",
  "shipping_address": "Sofia, Bulgaria",
  "shipping_provider": "speedy",
  "shipping_method": "office",
  "delivery_type": "office",
  "office_id": 1,
  "city": "Sofia",
  "postcode": "1000",
  "payment_method": "cash_on_delivery",
  "notes": "Please call before delivery",
  "terms": true
}
```

Server behavior:

- Recalculates all prices.
- Validates active/published products.
- Validates stock availability.
- Creates or updates the customer.
- Creates order and order items.
- Reduces stock.
- Clears the cart.
- Creates an `order_shipments` record.
- Creates a `payment_transactions` record.
- Returns payment instructions or redirect placeholders when applicable.
- Marks an abandoned cart as recovered when checkout is completed from a recovered cart session.
- Applies automatic promotions, coupon promotions, gift products and promotion redemption tracking through `PromotionEngineService`.

Response:

```json
{
  "data": {
    "order_number": "MC20260608-12345",
    "customer_email": "client@example.com",
    "subtotal": "200.00",
    "shipping_price": "8.99",
    "grand_total": "208.99",
    "status": "pending",
    "payment_status": "pending",
    "shipping_status": "pending"
  }
}
```

Payment method examples:

Cash on delivery:

```json
{
  "payment_method": "cash_on_delivery"
}
```

Bank transfer:

```json
{
  "payment_method": "bank_transfer"
}
```

Card placeholder:

```json
{
  "payment_method": "card"
}
```

Leasing placeholder:

```json
{
  "payment_method": "leasing"
}
```

### Payments

`GET /api/v1/payments/methods`

Returns active payment methods:

```json
{
  "data": [
    {
      "name": "РќР°Р»РѕР¶РµРЅ РїР»Р°С‚РµР¶",
      "code": "cash_on_delivery",
      "type": "offline",
      "description": "РџР»Р°С‰Р°РЅРµ РїСЂРё РґРѕСЃС‚Р°РІРєР°.",
      "instructions": null,
      "sort_order": 1
    }
  ]
}
```

`POST /api/v1/payments/initiate`

```json
{
  "order_id": 1,
  "payment_method_code": "card"
}
```

Response:

```json
{
  "data": {
    "transaction_id": "PAY-ABC123",
    "amount": "208.99",
    "currency": "EUR",
    "status": "pending",
    "redirect_url": "/payment/mock-card?order=MC20260608-12345",
    "instructions": null
  }
}
```

`POST /api/v1/payments/webhook/{provider}`

Placeholder endpoint for future online payment providers. Unknown providers return `404`. Signature validation is intentionally marked as a future placeholder.

### Shipping

`GET /api/v1/shipping/providers`

Returns active courier providers such as `manual`, `speedy` and `econt`.

`GET /api/v1/shipping/methods`

Returns active shipping methods for active providers.

`GET /api/v1/shipping/offices`

Query params: `provider`, `city`, `search`.

Example:

```text
/api/v1/shipping/offices?provider=speedy&city=Sofia&search=center
```

`POST /api/v1/shipping/calculate`

Office delivery:

```json
{
  "provider": "speedy",
  "delivery_type": "office",
  "shipping_method": "office",
  "office_id": 1,
  "city": "Sofia"
}
```

Address delivery:

```json
{
  "provider": "econt",
  "delivery_type": "address",
  "shipping_method": "address",
  "city": "Sofia",
  "postcode": "1000",
  "address": "bul. Bulgaria 1"
}
```

Response:

```json
{
  "data": {
    "shipping_price": "8.99",
    "estimated_delivery": "1-3 СЂР°Р±РѕС‚РЅРё РґРЅРё",
    "provider": "speedy",
    "method": "address"
  }
}
```

Mock Speedy/Econt providers are seeded with placeholder offices and can be configured from Filament. Real credentials should be stored in encrypted provider credentials, not hardcoded.

### Wishlist

Wishlist endpoints require Sanctum authentication.

- `GET /api/v1/account/wishlists`
- `POST /api/v1/account/wishlists`
- `PATCH /api/v1/account/wishlists/{wishlist}`
- `DELETE /api/v1/account/wishlists/{wishlist}`
- `GET /api/v1/account/wishlists/{wishlist}/items`
- `POST /api/v1/account/wishlists/{wishlist}/items`
- `DELETE /api/v1/account/wishlists/{wishlist}/items/{product}`
- `POST /api/v1/account/wishlist/toggle`

Create wishlist request:

```json
{
  "name": "Gaming build",
  "is_default": false
}
```

Add/toggle product request:

```json
{
  "product_id": 123
}
```

Response:

```json
{
  "data": {
    "id": 1,
    "name": "Р›СЋР±РёРјРё РїСЂРѕРґСѓРєС‚Рё",
    "is_default": true,
    "items_count": 1,
    "items": [
      {
        "id": 10,
        "product_id": 123,
        "product": {
          "id": 123,
          "name": "Lenovo LOQ",
          "slug": "lenovo-loq",
          "price": "2199.00"
        }
      }
    ]
  }
}
```

Rules:

- Guests cannot persist wishlist data server-side.
- Users can access only their own wishlists.
- Inactive/unpublished products cannot be added.
- Duplicate product additions are ignored.
- The default wishlist cannot be deleted.

### Persistent Compare Lists

Compare list endpoints support guests through `X-Compare-Session` and authenticated users through Sanctum bearer tokens.

- `GET /api/v1/compare/list`
- `POST /api/v1/compare/items`
- `DELETE /api/v1/compare/items/{product}`
- `DELETE /api/v1/compare/list`
- `POST /api/v1/compare/merge`

Add product:

```json
{
  "product_id": 123
}
```

Response:

```json
{
  "data": {
    "id": 1,
    "session_id": "11111111-1111-4111-8111-111111111111",
    "name": "Р“РѕСЃС‚ СЃСЂР°РІРЅРµРЅРёРµ",
    "max_products": 4,
    "items_count": 2,
    "items": [
      {
        "product_id": 123,
        "sort_order": 1,
        "product": {
          "id": 123,
          "name": "Lenovo LOQ",
          "slug": "lenovo-loq"
        }
      }
    ]
  }
}
```

Rules:

- Compare lists are limited to 4 products by default.
- Only active and published products can be compared.
- Duplicate products are ignored.
- `POST /api/v1/compare/merge` merges a guest session list into the authenticated user's compare list after login.
- Existing `POST /api/v1/compare` remains compatible and accepts `product_ids[]`.

### Product Reviews

Public endpoints:

- `GET /api/v1/products/{slug}/reviews`
- `POST /api/v1/products/{slug}/reviews`
- `POST /api/v1/reviews/{review}/vote`
- `POST /api/v1/reviews/{review}/report`

Account endpoint:

- `GET /api/v1/account/reviews`

Review list query params:

- `page`
- `per_page`
- `rating`
- `sort`: `newest`, `oldest`, `highest_rating`, `lowest_rating`, `most_helpful`

Review submit request:

```json
{
  "rating": 5,
  "title": "РњРЅРѕРіРѕ РґРѕР±СЉСЂ Р»Р°РїС‚РѕРї",
  "comment": "Р Р°Р±РѕС‚Рё Р±СЉСЂР·Рѕ Рё С‚РёС…Рѕ.",
  "pros": "Р”РѕР±СЉСЂ РµРєСЂР°РЅ, Р±СЉСЂР· SSD",
  "cons": "РќСЏРјР° РѕРїРµСЂР°С†РёРѕРЅРЅР° СЃРёСЃС‚РµРјР°",
  "customer_name": "РРІР°РЅ РџРµС‚СЂРѕРІ",
  "customer_email": "ivan@example.com"
}
```

Authenticated users do not need to send `customer_name` or `customer_email`; the API uses their account data.

Review list response:

```json
{
  "data": [
    {
      "id": 1,
      "customer_name": "РРІР°РЅ РџРµС‚СЂРѕРІ",
      "rating": 5,
      "title": "РњРЅРѕРіРѕ РґРѕР±СЉСЂ Р»Р°РїС‚РѕРї",
      "comment": "Р Р°Р±РѕС‚Рё Р±СЉСЂР·Рѕ Рё С‚РёС…Рѕ.",
      "pros": "Р”РѕР±СЉСЂ РµРєСЂР°РЅ",
      "cons": null,
      "is_verified_purchase": true,
      "helpful_votes_count": 3,
      "not_helpful_votes_count": 0,
      "created_at": "2026-06-08T12:00:00.000000Z"
    }
  ],
  "summary": {
    "average_rating": 4.7,
    "total_reviews": 12,
    "reviews_count": 12,
    "verified_reviews_count": 8,
    "rating_distribution": {
      "1": 0,
      "2": 1,
      "3": 1,
      "4": 2,
      "5": 8
    }
  }
}
```

Vote request:

```json
{
  "vote_type": "helpful"
}
```

Allowed vote types:

- `helpful`
- `not_helpful`

Report request:

```json
{
  "reason": "spam",
  "message": "Looks suspicious"
}
```

Rules:

- Only approved reviews are shown publicly.
- New reviews are `pending` until admin moderation.
- Rating must be from 1 to 5.
- Only active and published products can receive reviews.
- Duplicate reviews are blocked per product/user and per product/guest email.
- Customer email is never exposed publicly.
- Review submit, vote and report endpoints are rate limited with `throttle:reviews`.

### Blog And SEO Content

Blog endpoints:

- `GET /api/v1/blog`
- `GET /api/v1/blog/categories`
- `GET /api/v1/blog/categories/{slug}`
- `GET /api/v1/blog/categories/{slug}/posts`
- `GET /api/v1/blog/tags`
- `GET /api/v1/blog/tags/{slug}`
- `GET /api/v1/blog/{slug}`

Blog list query params:

- `page`
- `per_page`
- `category`
- `tag`
- `search`

Blog post response includes title, slug, excerpt, category, tags, author, reading time, views count and SEO fields. Detail responses include sanitized rich content, related products/categories/brands and Article JSON-LD-ready data.

SEO page endpoints:

- `GET /api/v1/pages/{slug}`
- `GET /api/v1/seo-pages/{slug}`

SEO page responses include:

- content
- responsive profiles
- preview modes
- related category
- related brand
- related products
- SEO metadata
- schema type/data

`content` is backward compatible:

- Legacy pages return `content` as an HTML string.
- CMS Builder pages return `content` as an array of responsive blocks.

Responsive block shape:

```json
{
  "id": "block-0",
  "type": "hero",
  "data": {
    "heading": "Gaming laptop campaign",
    "text": "Campaign copy"
  },
  "responsive": {
    "desktop": {
      "visible": true,
      "layout": { "width": "full", "max_width": "1200px", "columns": 4, "spacing": "lg", "alignment": "center" },
      "typography": { "heading_size": "4xl", "subtitle_size": "xl", "text_size": "md" },
      "buttons": { "layout": "inline", "alignment": "center", "full_width": false },
      "height": "700px",
      "carousel": { "slides_per_view": 5 },
      "ordering": { "media_first": true }
    },
    "tablet": {},
    "mobile": {}
  },
  "images": {
    "desktop": "cms/desktop/hero.jpg",
    "tablet": "cms/tablet/hero.jpg",
    "mobile": "cms/mobile/hero.jpg"
  },
  "preview": {
    "modes": ["desktop", "tablet", "mobile"],
    "default_mode": "desktop"
  }
}
```

Frontend rendering rules:

- Mobile-first settings are applied first, with tablet and desktop breakpoints layered above.
- Hidden blocks receive breakpoint visibility classes.
- Responsive images use `picture` sources so mobile browsers can choose the mobile asset instead of loading desktop imagery.
- Missing mobile images fall back to tablet, then desktop.

Only `published` content with `published_at <= now()` is public. Draft, scheduled and archived content is hidden.

Content Blocks CMS endpoints:

- `GET /api/v1/content/homepage`
- `GET /api/v1/content/pages/{slug}`
- `GET /api/v1/content/templates`
- `GET /api/v1/content/block-types`

Content page response:

```json
{
  "data": {
    "id": 1,
    "title": "Black Friday",
    "slug": "black-friday",
    "page_type": "campaign_page",
    "published_at": "2026-06-10T09:00:00.000000Z",
    "seo": {
      "meta_title": "Black Friday hardware deals",
      "meta_description": "Campaign landing page",
      "meta_keywords": null,
      "canonical_url": "https://mycomputer.bg/pages/black-friday"
    },
    "responsive_profiles": {
      "desktop": "1200px+",
      "tablet": "768px-1199px",
      "mobile": "below 768px"
    },
    "preview_modes": ["desktop", "tablet", "mobile"],
    "blocks": []
  }
}
```

CMS block responses include `settings`, `data`, `responsive`, `images`, optional `resolved` catalog data and analytics metadata. Product, category, brand and bundle blocks use public API resources and must not expose purchase prices, supplier payloads or internal admin fields.

CMS HTML output is sanitized before it is returned for `rich_text`, `image_text` and `custom_html` blocks. Unsafe tags, event attributes and `javascript:` URLs are removed. Blocks hidden on all device profiles are omitted from the response.

When visible FAQ blocks exist, content page responses include a schema.org `FAQPage` payload:

```json
{
  "schema": {
    "@context": "https://schema.org",
    "@type": "FAQPage",
    "mainEntity": [
      {
        "@type": "Question",
        "name": "How long is delivery?",
        "acceptedAnswer": {
          "@type": "Answer",
          "text": "One day"
        }
      }
    ]
  }
}
```

Nuxt public CMS route:

- `/content/{slug}` uses `GET /api/v1/content/pages/{slug}`.
- `/pages/{slug}` remains reserved for legacy SEO page content.

Sitemap and robots:

- `GET /sitemap.xml`
- `GET /robots.txt`
- `GET /api/v1/sitemap.xml`
- `GET /api/v1/robots.txt`

The sitemap includes public products, active categories, active brands, published blog posts and published SEO pages.

Redirect behavior:

- Active redirects are resolved from the web fallback route.
- `source_url` must be a relative path such as `/old-page`.
- `target_url` must be relative or inside `mycomputer.bg`.
- Supported status codes are `301` and `302`.

### AI Product Assistant

AI endpoints use provider abstraction and currently run through the mock provider.

- `POST /api/v1/ai/chat`
- `POST /api/v1/ai/search`
- `POST /api/v1/ai/compare`
- `POST /api/v1/ai/buying-guide`
- `GET /api/v1/ai/conversations`
- `GET /api/v1/ai/conversations/{conversation}`
- `DELETE /api/v1/ai/conversations/{conversation}`
- `GET /api/v1/products/{slug}/alternatives`

Use `X-AI-Session` for guest conversation continuity. Authenticated users are linked by Sanctum token.

AI search request:

```json
{
  "query": "Need a laptop for architecture under 3000 EUR"
}
```

AI search response:

```json
{
  "data": {
    "query": "Need a laptop for architecture under 3000 EUR",
    "intent": {
      "category_keywords": ["laptop", "autocad"],
      "price_max": 3000
    },
    "summary": "РџРѕРґР±СЂР°С… РїСЂРѕРґСѓРєС‚Рё СЃРїРѕСЂРµРґ Р·Р°СЏРІРєР°С‚Р°.",
    "reasoning": ["Product X Рµ РїРѕРґС…РѕРґСЏС‰ РёР·Р±РѕСЂ."],
    "products": []
  }
}
```

Chat request:

```json
{
  "message": "Търся геймърски лаптоп до 2500 EUR.",
  "conversation_id": 1
}
```

Product alternatives:

```http
GET /api/v1/products/{slug}/alternatives
```

Returns cheaper, better and similar alternatives. Public responses never expose purchase price, supplier payloads or internal supplier data.

AI endpoints are rate limited with `throttle:ai`.

### PC Builder

Guest PC builds are owned by the `X-PC-Build-Session` header. Authenticated users are matched by Sanctum `user_id`.

```http
GET /api/v1/pc-builder
GET /api/v1/pc-builder/builds
GET /api/v1/pc-builder/builds/{build}
POST /api/v1/pc-builder/builds
PATCH /api/v1/pc-builder/builds/{build}
DELETE /api/v1/pc-builder/builds/{build}
POST /api/v1/pc-builder/builds/{build}/items
DELETE /api/v1/pc-builder/builds/{build}/items/{item}
GET /api/v1/pc-builder/builds/{build}/compatibility
GET /api/v1/pc-builder/builds/{build}/recommendations
POST /api/v1/pc-builder/builds/{build}/add-to-cart
POST /api/v1/pc-builder/ai-generate
```

Create build:

```json
{
  "name": "Gaming PC до 3000 EUR",
  "description": "Конфигурация за 1440p gaming"
}
```

Add component:

```json
{
  "product_id": 123,
  "component_type": "gpu",
  "quantity": 1
}
```

Supported component types:

- `cpu`
- `motherboard`
- `ram`
- `gpu`
- `psu`
- `case`
- `storage`
- `cooler`
- `operating_system`
- `monitor`
- `keyboard`
- `mouse`
- `speakers`
- `accessories`

Compatibility response:

```json
{
  "data": {
    "compatible": false,
    "warnings": ["PSU may be insufficient: 650W available, 750W recommended."],
    "errors": ["ram memory_type (DDR4) is not compatible with motherboard memory_type (DDR5)."],
    "recommendations": ["CPU socket matches motherboard."]
  }
}
```

AI build generation:

```json
{
  "query": "Build me a gaming PC under 3000 EUR"
}
```

The AI endpoint creates a draft build through the PC Builder service and validates compatibility afterwards. Product recommendations are returned through public product resources and do not expose `purchase_price`, supplier payloads or raw import data.

### Marketing, Analytics And Feeds

Public feed URLs:

```http
GET /feeds/google-merchant.xml
GET /feeds/facebook-catalog.xml
```

Admin-only analytics endpoints require Sanctum authentication and the `manage marketing` permission:

```http
GET /api/v1/analytics/dashboard
GET /api/v1/feeds/status
POST /api/v1/feeds/generate
GET /api/v1/marketing/events
```

Public storefront event logging endpoint:

```http
POST /api/v1/marketing/events
```

Event payload:

```json
{
  "event_name": "search",
  "source": "ga4",
  "payload": {
    "query": "rtx 5070",
    "results_count": 12
  }
}
```

Feed generation payload:

```json
{
  "feed_type": "google_merchant"
}
```

Supported feed types:

- `google_merchant`
- `facebook_catalog`

Feed generation response:

```json
{
  "data": {
    "status": "queued",
    "feed_type": "google_merchant"
  }
}
```

Tracked storefront events include:

- GA4: `page_view`, `view_item`, `view_item_list`, `search`, `add_to_cart`, `remove_from_cart`, `begin_checkout`, `add_payment_info`, `purchase`, `sign_up`, `login`
- Meta Pixel: `PageView`, `ViewContent`, `Search`, `AddToCart`, `InitiateCheckout`, `AddPaymentInfo`, `Purchase`, `CompleteRegistration`
- Internal: `BuilderStart`, `BuilderSave`, `BuilderComplete`, `AiConversationStart`, `AiRecommendationAccepted`

Security notes:

- Public product feeds do not expose `purchase_price`, supplier credentials, raw import payloads or admin-only data.
- Admin dashboard and feed regeneration routes are protected by `manage marketing`.
- Frontend tracking is consent-ready through `mycomputer_consent` local storage and should be replaced by a real GDPR consent manager before production launch.

### Newsletter And Email Alerts

`POST /api/v1/newsletter/subscribe`

Payload:

```json
{
  "email": "client@example.com",
  "first_name": "Ivan",
  "last_name": "Petrov",
  "source": "newsletter",
  "gdpr_consent": true
}
```

`POST /api/v1/newsletter/unsubscribe`

Payload:

```json
{
  "email": "client@example.com"
}
```

`GET /api/v1/newsletter/status?email=client@example.com`

Returns subscription status without exposing provider internals.

Product alerts:

`POST /api/v1/products/{product}/price-alerts`

```json
{
  "email": "client@example.com",
  "target_price": 1499
}
```

`POST /api/v1/products/{product}/stock-alerts`

```json
{
  "email": "client@example.com"
}
```

Email compliance notes:

- Duplicate subscriptions update the existing subscriber.
- Marketing emails are skipped for `unsubscribed`, `bounced`, and `suppressed` subscribers.
- Provider payloads are logged in `email_logs`.
- Current provider is configurable through `EMAIL_MARKETING_PROVIDER`.

### Loyalty And Rewards

Authenticated loyalty dashboard:

```http
GET /api/v1/account/loyalty
```

Returns:

```json
{
  "data": {
    "tier": "silver",
    "points_balance": 1250,
    "lifetime_points": 1800,
    "next_tier": {
      "tier": "gold",
      "threshold": 5000,
      "remaining_points": 3200,
      "progress_percentage": 36
    },
    "recent_transactions": []
  }
}
```

Public rewards catalog:

```http
GET /api/v1/rewards
GET /api/v1/rewards/{reward}
```

Redeem reward voucher:

```http
POST /api/v1/rewards/redeem
Authorization: Bearer {token}
```

Payload:

```json
{
  "reward_id": 1
}
```

Response:

```json
{
  "data": {
    "code": "LOYALTY10",
    "redeemed_points": 100
  }
}
```

Checkout reward usage:

```json
{
  "reward_code": "LOYALTY10"
}
```

Loyalty rules:

- Reward redemption requires Sanctum authentication.
- Checkout voucher usage requires the cart to belong to the authenticated user.
- Voucher validation checks active state, date window, usage limit, minimum order amount and available points.
- Point balances cannot become negative.
- Duplicate voucher redemption by the same user is blocked.
- Loyalty internals never expose purchase prices, supplier data or raw import payloads.

### SEO

`GET /api/v1/seo/product/{slug}`

`GET /api/v1/seo/category/{slug}`

`GET /api/v1/seo/brand/{slug}`

Returns meta title, meta description, canonical URL placeholder and schema.org-ready data.

### Product Availability

Product list, detail, category-products, brand-products and search product card payloads include `availability`.

Example:

```json
{
  "availability": {
    "code": "preorder",
    "name": "Preorder",
    "color": "blue",
    "icon": "clock",
    "badge_style": "soft",
    "allow_purchase": true,
    "show_stock_quantity": false,
    "message": "Expected in 7 days",
    "expected_date": "2026-07-01",
    "supplier_lead_time_days": 7
  }
}
```

Supported product/search filters:

- `availability=preorder`
- `availability_status=incoming`
- `availability_statuses[]=in_stock&availability_statuses[]=limited_stock`

Search available filters may include `availability_statuses` with counts, label, color, icon and purchase behavior.

Public API responses keep legacy `stock_status` for compatibility, but storefronts should prefer `availability`.

### Admin ERP

ERP endpoints are authenticated with Sanctum and require admin permissions. They never expose provider credentials.

`GET /api/v1/admin/erp/status`

Requires `view erp logs`.

Returns active provider metadata, pending/failed/success counters and provider count.

`POST /api/v1/admin/erp/sync/order/{order}`

Requires `manage erp`.

Queues an order push sync job.

`POST /api/v1/admin/erp/sync/customer/{customer}`

Requires `manage erp`.

Queues a customer push sync job.

## Example Responses

Product card:

```json
{
  "data": [
    {
      "id": 1,
      "sku": "MC-LAP-001",
      "name": "Lenovo ThinkPad E16 Gen 2",
      "slug": "lenovo-thinkpad-e16-gen-2",
      "price": "1699.00",
      "promo_price": null,
      "stock_status": "in_stock",
      "availability": {
        "code": "in_stock",
        "name": "In Stock",
        "color": "green",
        "icon": "check",
        "badge_style": "soft",
        "allow_purchase": true,
        "show_stock_quantity": true,
        "message": null,
        "expected_date": null,
        "supplier_lead_time_days": null
      },
      "brand": {"name": "Lenovo", "slug": "lenovo"},
      "category": {"name": "Business Laptops", "slug": "business-laptops"}
    }
  ]
}
```

Error format:

```json
{
  "success": false,
  "error": {
    "code": "validation_error",
    "message": "The given data was invalid.",
    "details": {
      "per_page": ["The per page field must not be greater than 100."]
    }
  }
}
```
