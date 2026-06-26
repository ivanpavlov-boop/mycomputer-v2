# Multilingual Foundation

## Purpose

Phase 8.6A prepares mycomputer.bg v2 for Bulgarian and English catalog content without translating the catalog automatically and without changing catalog sync write behavior.

## Supported Locales

Configured in `config/locales.php`:

| Locale | Label | URL behavior |
| --- | --- | --- |
| `bg` | Български | Default locale, no required URL prefix. |
| `en` | English | Secondary locale, prepared for `/en/...` URLs. |

Application defaults:

- default locale: `bg`
- fallback locale: `bg`
- current staging `APP_URL`: unchanged

## URL Strategy

Bulgarian remains the default public language and keeps existing URLs working. English is prepared under `/en`.

Target shape:

- BG: `/laptopi/lenovo-thinkpad-e16`
- EN: `/en/laptops/lenovo-thinkpad-e16`

This phase does not force a `/bg` prefix and does not rewrite the Nuxt router. Backend helpers can generate locale-prefixed paths for future storefront integration.

## Storage Strategy

Existing scalar columns remain the Bulgarian/default source of truth. New nullable JSON translation columns store optional localized values.

Examples:

- `products.name` remains the current Bulgarian/default fallback.
- `products.name_translations->en` can store an English product name.
- `categories.slug` remains the current Bulgarian/default slug.
- `categories.slug_translations->en` can store an English slug.

No existing fields are dropped, renamed, or regenerated.

## Current Translatable Areas

Prepared entities:

- Products: name, slug, short description, description, SEO title, SEO description.
- Categories: name, slug, description, SEO title, SEO description.
- Brands: description, SEO title, SEO description.
- Attribute groups: label and description.
- Product attributes: label.
- Attribute values: option label.
- SEO pages: title, slug, content, SEO title, SEO description.
- Content pages: title, slug, SEO title, SEO description.

English fields are optional. Missing English values are visible as missing in admin and do not block Bulgarian launch.

## Fallback Rules

- Bulgarian is primary and required through the existing scalar fields.
- English is optional.
- API responses keep existing scalar keys for backward compatibility.
- New localized payloads expose requested locale values without removing legacy keys.
- Technical values are not translated.

Do not translate:

- price
- currency
- stock
- SKU / supplier SKU
- EAN / MPN
- quantity
- weight / dimensions
- numeric specifications
- warranty numbers

Translate labels and marketing text only.

## Admin UX

Product, category, brand, and attribute admin forms expose collapsed English localization sections. The existing Bulgarian/default fields remain in their current locations so current workflows stay familiar.

## SEO And Hreflang

`App\Support\Seo\HreflangLinks` prepares `bg`, `en`, and `x-default` links. English hreflang links should be emitted only when a safe English path exists. Full sitemap expansion is deferred to a future phase.

## Supplier Sync Safety

Supplier import and catalog sync behavior is unchanged.

Supplier sync must not overwrite:

- localized product names
- localized slugs
- localized SEO fields
- localized descriptions
- localized category text
- localized attributes

Manual selected UPDATE remains limited to commercial fields. Sync All, automatic sync, scheduled sync, and image sync remain disabled.

## Future Work

- Nuxt i18n route integration.
- Locale switcher UX.
- Localized sitemap output.
- Translation completeness reports.
- Optional approval workflow for English content.
