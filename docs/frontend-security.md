# Frontend Security Notes

Nuxt must treat the Laravel API as the source of truth.

Rules:

- Never trust frontend cart totals, stock status or prices.
- Keep tokens out of localStorage when cookie-based Sanctum flows are available.
- Use server-side rendering without exposing admin-only fields.
- Do not render raw HTML from product descriptions unless sanitized.
- Add consent-aware analytics before enabling GA4 and Meta scripts.
- Keep `NUXT_PUBLIC_API_BASE_URL` environment-specific.
- Avoid exposing supplier data, purchase prices, import payloads, internal notes or raw XML/CSV data.

Recommended headers are set at the Nginx layer and should be mirrored on the Nuxt host:

- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy: camera=(), microphone=(), geolocation=()`
- CSP should be introduced before marketing tags go live.
