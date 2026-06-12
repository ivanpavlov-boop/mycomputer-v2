# ERP Integration Layer

The ERP layer prepares mycomputer.bg v2 for future synchronization with Microinvest, ERP.NET, Business Navigator or another ERP provider. It is not a full ERP implementation and does not contain provider-specific fiscal or accounting rules yet.

## Architecture

Business code depends on `App\Services\Erp\Contracts\ErpProviderInterface`.

Current providers:

- `ManualErpProvider`: no external calls; keeps work pending for admin/manual processing.
- `MockErpProvider`: deterministic success responses for tests and staging.
- `MicroinvestProvider`: placeholder, unsupported until real integration starts.
- `ErpNetProvider`: placeholder, unsupported until real integration starts.
- `BusinessNavigatorProvider`: placeholder, unsupported until real integration starts.

Main persistence:

- `erp_providers`
- `erp_sync_jobs`
- `erp_documents`
- `erp_product_mappings`
- `erp_customer_mappings`

Credentials are encrypted at rest through Eloquent casts and must not be returned by APIs.

## Order Sync Flow

1. Checkout creates an order.
2. `OrderCreated` is dispatched.
3. `QueueOrderErpSync` creates an `erp_sync_jobs` record.
4. If an active ERP provider exists, `SyncOrderToErpJob` is queued on the `erp` queue.
5. The job sends customer data first when available, then sends the order.
6. The sync log stores response, external ID, status and timestamp.

## Invoice Flow

`CreateErpInvoiceJob` calls `createInvoice(Order $order)` on the configured provider.

For mock provider, it creates:

- ERP sync success response
- `erp_documents` invoice record
- mock external document ID
- mock document number

Real invoice rules are intentionally deferred to provider-specific implementation.

## Payment Flow

Payment status changes dispatch `OrderPaymentStatusChanged`.

The listener creates a payment sync job. If a provider is active, `SyncPaymentToErpJob` sends:

- order number
- payment method
- payment status
- paid amount
- paid date when available

## Stock Sync Flow

Command:

```bash
php artisan erp:pull-stock
php artisan erp:pull-stock mock
```

Current behavior:

- Manual provider returns skipped/no-op.
- Mock provider returns fake stock rows.
- Real provider stock updates are not implemented yet.

Future real providers should map external SKU/barcode through `erp_product_mappings` before changing catalog stock.

## Future Microinvest Integration Steps

1. Confirm API/database transport supported by the deployed Microinvest environment.
2. Define product, customer, order, payment and invoice payload mapping.
3. Implement authentication in `MicroinvestProvider`.
4. Implement `pushCustomer`, `pushOrder`, `pushPayment`, `createInvoice` and `pullStock`.
5. Add provider-specific tests with fake transport.
6. Validate invoice numbering and fiscal/legal requirements outside the generic layer.

## Future ERP.NET Integration Steps

1. Add ERP.NET credentials/settings schema.
2. Implement API client inside `ErpNetProvider`.
3. Map customer/company and document IDs into mapping tables.
4. Add stock pull using external SKU/barcode mapping.
5. Add retry and rate-limit handling according to ERP.NET API rules.

## Future Business Navigator Integration Steps

1. Confirm available integration transport and authentication.
2. Implement provider-specific client in `BusinessNavigatorProvider`.
3. Map order/payment/invoice payload fields.
4. Store returned document IDs and numbers in `erp_documents`.
5. Add staging sync validation before production enablement.

## Admin Tools

Filament resources:

- ERP Providers
- ERP Sync Jobs
- ERP Documents
- ERP Product Mappings
- ERP Customer Mappings

Order actions:

- Send to ERP
- Create ERP invoice
- View ERP documents

Permissions:

- `manage erp`
- `view erp logs`
- `retry erp sync`
