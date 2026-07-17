# APCOM Authoritative Human Decisions V2

## Evidence Classification

`apcom-human-decisions-v2` supersedes the historical v1 register without
rewriting it. The confirmed entries use descriptive evidence codes of type
`operator_confirmed_business_evidence`. They are operator-confirmed business
evidence, not official written APCOM documentation.

## Confirmed Stock And Availability Semantics

APCOM `stock` is a non-negative integer
`supplier_available_quantity_snapshot`, not a real-time promise. The APCOM
policy key is `apcom-availability-policy-v1`:

- active `stock=0` -> `on_request`, with availability confirmation required;
- active `stock=1..5` -> `limited`;
- active `stock>=6` -> `in_stock`; and
- `stock=100` means `100_or_more`, is capped, and has minimum `100`.

Exact supplier quantities are hidden publicly. The low-stock threshold is `5`.
No catalog quantity write is approved.

## Lifecycle

`eol=0` maps to `active` and `eol=1` maps to `eol`. EOL with positive stock is
`last_units`; EOL with zero stock is `discontinued`. Neither result permits
automatic delete, unpublish, unlink, workflow change, or catalog-page change.
Actual EOL populations remain review-only.

## Price, Currency, VAT, And Green Tax

`fd_price` is confirmed as `supplier_purchase_price` in `EUR`, VAT
`exclusive`: supplier cost without VAT is exactly `fd_price`. `dac_price`
remains an observable candidate and is not automatically selected.

Green Tax is included in `fd_price`; no separate amount is added. Missing
`greentax` does not add a second charge. Any later separate tax, invoice fee,
or contradictory official supplier evidence returns the decision to review.

## Remaining Decisions

MPN remains pending. Source-only, staging-only, and linked staging-only
handling remain pending. Zero-price handling is review-only. The stale snapshot
threshold and cart-limit policy are not approved.

Automatic import, schedule enablement, Sync All, automatic Catalog Sync,
Catalog Sync UPDATE, image import, content overwrite, automatic CREATE,
automatic DELETE, automatic LINK, and automatic UNLINK remain prohibited.

## Approval Gate

The immutable approval gate is valid but has status
`blocked_pending_human_decisions`. Semantic confirmation remains incomplete;
operational import, profile persistence, schedule enablement, and Catalog Sync
approval are all false. This document does not authorize import execution.

## V3 Missing Offer Addendum

Phase 9C.6.5C.3D preserves this v2 evidence unchanged and adds the separate,
non-executable [APCOM Missing Offer Decisions V3](APCOM_MISSING_OFFER_DECISIONS_V3.md).
The v3 policy confirms preview semantics for qualified missing-offer observations
without approving persistence, offer writes, product visibility writes,
storefront behavior, sitemap/noindex behavior, import, schedule, or Catalog
Sync.
