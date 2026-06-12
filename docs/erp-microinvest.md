# Microinvest ERP Provider

This document describes the future Microinvest ERP integration skeleton for `mycomputer.bg` v2.

The current implementation is intentionally disabled for real external communication. It prepares provider classes, configuration placeholders and payload mappers only.

## Current Status

- Provider code exists: `App\Services\Erp\Providers\MicroinvestProvider`
- API client exists as a placeholder: `MicroinvestApiClient`
- External calls are disabled and always report `external_calls_enabled = false`
- ERP jobs can resolve the provider, but Microinvest responses remain `not_configured` or `unsupported`
- No Microinvest endpoint is contacted

## Required Credentials

Store credentials on the `erp_providers.credentials` encrypted JSON column:

```json
{
  "username": "microinvest-user",
  "password": "microinvest-password"
}
```

Store non-secret settings on `erp_providers.settings`:

```json
{
  "enabled": false,
  "base_url": "https://microinvest.example/api",
  "database_code": "DATABASE",
  "company_code": "COMPANY",
  "warehouse_code": "MAIN",
  "invoice_series": "A",
  "payment_method_mapping": {
    "cash_on_delivery": "COD",
    "bank_transfer": "BANK",
    "card": "CARD"
  },
  "vat_settings": {
    "default_rate": 20,
    "prices_include_vat": true
  }
}
```

Secrets must never be returned by API responses, admin status endpoints, logs or sync payloads.

## Future Integration Steps

1. Confirm the exact Microinvest product/API interface available for the production ERP installation.
2. Define authentication flow and token/session lifetime.
3. Replace placeholder methods in `MicroinvestApiClient` with authenticated HTTP calls.
4. Add request signing, timeout, retry and circuit-breaker behavior.
5. Map Microinvest error codes into stable ERP sync failure reasons.
6. Add staging-only integration tests against a Microinvest sandbox or mock server.
7. Enable the provider only after credentials, warehouse codes and invoice rules are verified.

## Customer Mapping

`MicroinvestCustomerMapper` prepares:

- customer type: individual or company
- name, first name, last name
- email and phone
- company name and VAT number
- billing and shipping addresses

Known unknowns:

- Whether Microinvest requires separate customer and company records
- Whether VAT validation is handled by Microinvest or by this application
- Required address field granularity

## Order Mapping

`MicroinvestOrderMapper` prepares:

- order number
- warehouse code
- customer snapshot
- totals
- payment method and status
- product lines
- bundle summary lines

Known unknowns:

- Whether bundles should be pushed as summary lines or expanded component lines
- Exact warehouse reservation behavior
- Required document type for pending online orders

## Invoice Mapping

`MicroinvestInvoiceMapper` prepares:

- invoice document type
- invoice series
- company code
- mapped order payload

Known unknowns:

- Invoice numbering ownership
- Credit note and refund document flow
- Whether proforma invoices are required before fiscal invoices

## Payment Mapping

`MicroinvestPaymentMapper` prepares:

- order number
- application payment method
- Microinvest payment method code from settings
- payment status
- amount and currency

Known unknowns:

- Whether partial payments are supported
- Whether cash on delivery should be synced before delivery confirmation
- Card payment settlement timing

## Stock Mapping

`MicroinvestStockMapper` prepares:

- stock pull request payload with company and warehouse codes
- normalized stock rows from future Microinvest responses

Known unknowns:

- Product identifier priority: SKU, barcode, Microinvest item code
- Multi-warehouse availability logic
- Whether incoming ERP stock should reserve pending web orders

## Safety Rules

- Microinvest must not become the active production provider until the client is implemented and staging-tested.
- Provider responses must not expose `username`, `password`, tokens or API keys.
- Failed sync jobs should keep masked payloads only.
- Real integration must keep queue-based execution and never block checkout.
