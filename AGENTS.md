# AGENTS.md

Guidance for coding agents working on `mycomputer.bg` v2.

## Project

`mycomputer.bg` v2 is a Laravel 12 e-commerce backend for computer hardware, laptops, components, printers, monitors, and accessories. The admin surface is Filament, the database target is MySQL, and the public frontend is expected to be a future Nuxt app consuming JSON APIs.

Current phase scope:

- Catalog foundation
- Categories
- Brands
- Products
- Product images
- Product attributes
- Suppliers
- Supplier XML/CSV feed structure
- Basic read-only catalog API endpoints

Do not build orders, payments, carts, customer accounts, or Nuxt frontend screens unless the user explicitly asks for that phase.

## Local Runtime

This workspace includes a portable PHP and Composer setup under `.tools/`. It is intentionally ignored by git.

Preferred Windows commands from the repository root:

```powershell
.\.tools\php\php.exe .\.tools\composer.phar install
.\.tools\php\php.exe artisan key:generate
.\.tools\php\php.exe artisan migrate --seed
.\.tools\php\php.exe artisan serve
```

If global PHP and Composer are available, normal Laravel commands are also fine:

```powershell
composer install
php artisan migrate --seed
php artisan serve
```

The default `.env.example` uses MySQL:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mycomputer_v2
DB_USERNAME=root
DB_PASSWORD=
```

For quick local validation without MySQL, use SQLite only as a temporary test runtime:

```powershell
$env:DB_CONNECTION='sqlite'
$env:DB_DATABASE=(Resolve-Path database\database.sqlite).Path
.\.tools\php\php.exe artisan migrate:fresh --seed
```

## Testing And Quality

Run these before handing work back:

```powershell
.\.tools\php\php.exe artisan test
.\.tools\php\php.exe vendor\bin\pint --dirty
```

Useful checks:

```powershell
.\.tools\php\php.exe artisan route:list --path=api
.\.tools\php\php.exe artisan migrate:fresh --seed
```

Testing rules:

- Add feature tests for API behavior and important admin-adjacent workflows.
- Use `RefreshDatabase` for database-backed tests.
- Keep seeders deterministic enough to support tests and local demos.
- Do not require a live supplier feed, external API, or production service in tests.

## Architecture Rules

### Laravel Backend

- Keep business data in Eloquent models with explicit relationships, casts, and fillable fields.
- Prefer Laravel conventions over custom infrastructure.
- Put reusable domain constants or small contracts in `app/Support`.
- Keep controllers thin. Query, authorize, transform, and return resources.
- Use API Resources for JSON responses. Do not return raw model payloads from public API controllers.
- Public frontend routes belong under `routes/api.php`, versioned under `/api/v1`.

### Catalog Domain

Current core models:

- `Category`: hierarchical catalog taxonomy.
- `Brand`: product manufacturer/brand.
- `Product`: sellable catalog item.
- `ProductImage`: ordered product media.
- `ProductAttribute`: flexible product specs/filter data.
- `Supplier`: vendor/distributor.
- `SupplierFeed`: XML/CSV feed definition and mapping.
- `SupplierFeedItem`: raw imported feed item staging.

Rules:

- Keep category slugs and product slugs unique.
- Keep SKUs unique and stable.
- Supplier credentials must stay encrypted or otherwise protected.
- Preserve raw supplier payloads in staging structures when adding import logic.
- Do not overload `ProductAttribute` for every possible future concept if a first-class model becomes necessary.
- Keep CSV product column structure aligned with `App\Support\Catalog\ProductCsvSchema`.

### Filament Admin

Filament resources live under `app/Filament/Resources`.

Rules:

- Keep resource form schema classes in `Schemas/*Form.php`.
- Keep table definitions in `Tables/*Table.php`.
- Use relationship fields/repeaters rather than manual ID entry where practical.
- Keep navigation grouped by domain, currently `Catalog` and `Suppliers`.
- Do not put frontend/customer UX into Filament resources.

### API For Future Nuxt Frontend

Current endpoints:

- `GET /api/v1/categories`
- `GET /api/v1/brands`
- `GET /api/v1/products`
- `GET /api/v1/products/{slug}`

Rules:

- Return only active/published catalog data from public endpoints.
- Use pagination for product lists.
- Keep response shapes stable once consumed by Nuxt.
- Avoid leaking supplier cost, supplier credentials, raw payloads, or admin-only fields.

## Coding Standards

- Follow Laravel 12 and Filament 5 patterns already present in the repository.
- Use strict, readable names over abbreviations.
- Keep comments rare and useful; explain non-obvious import/feed mapping behavior when needed.
- Prefer migrations that can run cleanly from an empty database.
- Add indexes for fields used in filters, slugs, statuses, and foreign-key lookups.
- Avoid broad refactors while implementing a focused feature.
- Do not commit generated caches, local databases for production use, `.env`, `.tools`, `vendor`, or `node_modules`.

## Safe Change Boundaries

Before changing architecture, ask or clearly explain the choice when it affects:

- Authentication strategy beyond Filament admin login
- Customer accounts
- Orders and payments
- Inventory reservation
- Supplier import execution jobs
- Search engine choice
- Nuxt frontend structure
- Production deployment setup

When in doubt, extend the current catalog foundation conservatively and leave future phases prepared rather than partially built.
