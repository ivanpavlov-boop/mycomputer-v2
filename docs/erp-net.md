# ERP.NET Provider

This document describes the future ERP.NET integration skeleton for `mycomputer.bg` v2.

The current implementation is intentionally disabled for real external communication. It prepares provider classes, configuration placeholders and payload mappers only.

## Current Status

- Provider code exists: `App\Services\Erp\Providers\ErpNetProvider`
- API client exists as a placeholder: `ErpNetApiClient`
- External calls are disabled and always report `external_calls_enabled = false`
- ERP jobs can resolve the provider, but ERP.NET responses remain `not_configured` or `unsupported`
- No ERP.NET endpoint is contacted

## Required Credentials

Store credentials on the `erp_providers.credentials` encrypted JSON column:

```json
{
  "api_key": "erp-net-api-key"
}
```

Store non-secret settings on `erp_providers.settings`:

```json
{
  "enabled": false,
  "base_url": "https://erp-net.example/api",
  "company_id": "COMPANY-ID",
  "warehouse_id": "WAREHOUSE-ID",
  "price_list_id": "PRICE-LIST-ID",
  "invoice_document_type": "INVOICE",
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

1. Confirm the exact ERP.NET API version and endpoint structure.
2. Define authentication headers, token rotation and rate limits.
3. Replace placeholder methods in `ErpNetApiClient` with authenticated HTTP calls.
4. Add request timeouts, retry rules and provider-specific error mapping.
5. Confirm document lifecycle for sales orders, invoices, payments and cancellations.
6. Add staging-only integration tests against an ERP.NET sandbox or mock server.
7. Enable the provider only after company, warehouse, price list and document type IDs are verified.

## Customer Mapping

`ErpNetCustomerMapper` prepares:

- party type: person or company
- display name, first name, last name
- email and phone
- company name and tax number
- billing and shipping addresses

Known unknowns:

- Whether ERP.NET requires separate party, customer and company objects
- Required country/city/postcode field structure
- VAT number validation ownership

## Order Mapping

`ErpNetOrderMapper` prepares:

- sales order document type
- order number
- company, warehouse and price list IDs
- customer snapshot
- totals and currency
- payment method and status
- product lines
- bundle summary lines

Known unknowns:

- Whether bundle lines should be expanded into component items
- Required unit of measure identifiers
- Exact order state mapping between checkout and ERP.NET

## Invoice Mapping

`ErpNetInvoiceMapper` prepares:

- invoice document type
- configured ERP.NET invoice document type
- company ID
- mapped sales order payload

Known unknowns:

- Invoice numbering ownership
- Fiscalization requirements
- Credit note and refund flow

## Payment Mapping

`ErpNetPaymentMapper` prepares:

- order number
- application payment method
- ERP.NET payment method code from settings
- payment status
- amount and currency

Known unknowns:

- Whether payments attach to orders, invoices or both
- Partial payment behavior
- Card settlement timing

## Stock Mapping

`ErpNetStockMapper` prepares:

- stock pull request payload with company, warehouse and price list IDs
- normalized stock rows from future ERP.NET responses

Known unknowns:

- Product identifier priority: SKU, barcode, ERP.NET product ID
- Multi-warehouse availability rules
- Whether ERP.NET stock quantities include reservations

## Safety Rules

- ERP.NET must not become the active production provider until the client is implemented and staging-tested.
- Provider responses must not expose API keys or future tokens.
- Failed sync jobs should keep masked payloads only.
- Real integration must keep queue-based execution and never block checkout.
