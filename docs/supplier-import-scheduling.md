# Supplier Import Scheduling, Monitoring & Safety

Supplier import scheduling coordinates supplier feed ingestion without allowing raw supplier data to mutate catalog products directly.

## Launch Supplier Schedule

Initial suppliers are seeded with staggered twice-daily windows in `Europe/Sofia`:

| Supplier | Morning | Evening | Strategy |
| --- | ---: | ---: | --- |
| ASBIS | 06:00 | 19:00 | preferred supplier |
| ALSO | 06:20 | 19:20 | preferred supplier |
| PolyComp | 06:40 | 19:40 | preferred supplier |
| APCOM | 07:00 | 20:00 | lowest price |
| Most | 07:20 | 20:20 | lowest price |
| Decada | 07:40 | 20:40 | lowest price |

## Runtime Flow

1. `php artisan suppliers:run-scheduled-imports` runs every five minutes.
2. Due suppliers are selected from `suppliers.next_import_at`.
3. `RunSupplierImportJob` is dispatched on the `imports` queue.
4. A Redis/cache lock named `supplier_import:{supplier_id}` prevents overlapping imports.
5. XML feeds are imported into `supplier_products` first.
6. Safety checks run before Product Sync.
7. Product Sync updates or creates catalog products only after safety passes.
8. A `supplier_import_runs` report is generated.
9. Affected products are sent to the search index.

CSV supplier feeds are parsed through the CSV mapping service and staged into `supplier_products` before Product Sync. Future API supplier feeds should follow the same rule: stage raw supplier data first and sync catalog products only through Product Sync.

## Safety Rules

- Empty feeds fail the run.
- Product counts below `suppliers.minimum_product_count` fail the run.
- Product drops above `suppliers.maximum_product_drop_percent` create warnings and block sync unless `allow_destructive_sync` is enabled.
- Products are never deleted automatically.
- Manual force imports bypass the running-run guard but still execute safety/reporting paths.

## Admin Surfaces

Filament:

- `SupplierImportRunResource` lists import history, metrics, warnings, errors and reports.
- `SupplierImportStats` dashboard widget shows running imports, failures, completed imports, product updates, unmapped attributes, missing availability mappings and longest duration.
- Supplier table actions:
  - Run import
  - Force import

API:

- `GET /api/v1/admin/suppliers/import-runs`
- `GET /api/v1/admin/suppliers/{supplier}/import-runs`
- `POST /api/v1/admin/suppliers/{supplier}/run-import`
- `POST /api/v1/admin/suppliers/{supplier}/force-run-import`

All API endpoints require Sanctum authentication and supplier import permissions.

## Permissions

- `view supplier import logs`
- `run supplier imports`
- `force supplier imports`
- `manage supplier imports`

Administrators receive all permissions. Managers can manage, run and view supplier imports. Support staff can view supplier import logs.

## Operations

Run scheduled imports manually:

```bash
php artisan suppliers:run-scheduled-imports
```

Run queue workers for imports:

```bash
php artisan queue:work redis --queue=imports,sync,search,default
```

Review latest import runs:

```bash
php artisan tinker
App\Models\SupplierImportRun::latest()->take(10)->get();
```
