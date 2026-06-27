# Product Quality Flags

## Purpose

Product Quality Flags provide configurable, non-blocking admin reminders for catalog cleanup.

Examples:

- missing English SEO
- missing image
- needs Bulgarian description
- category review needed

## Data Model

Flags are stored in `product_quality_flags` and include:

- code
- Bulgarian and English labels
- Bulgarian and English descriptions
- severity
- responsible role
- type
- active state
- sort order

Products can have many assigned flags through `product_quality_flag_assignments`.

## Admin Behavior

Super Admin and Catalog Manager can manage the flag definitions. Flags can be assigned to products from the product form by authorized users.

Flags are non-blocking by default. A product with active quality flags can still be approved or published unless a future dedicated phase explicitly adds blocking rules.

## Safety

Quality flags do not change:

- supplier import
- pricing
- exclusions
- matching
- CREATE sync
- UPDATE sync
- product visibility by themselves

