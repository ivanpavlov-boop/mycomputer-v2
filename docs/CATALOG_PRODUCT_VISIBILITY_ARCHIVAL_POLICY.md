# Catalog Product Visibility And Archival Policy

## Multi-Supplier Aggregation

`catalog-offer-aggregation-policy-v1` evaluates all valid supplier offers for
one catalog product. A single missing, inactive, or EOL offer cannot deactivate
the catalog product when another valid offer is `in_stock`, `limited`,
`on_request`, or `last_units`. Invalid or zero-price offers do not override a
valid active offer.

## Zero Active Offers

`catalog-product-visibility-lifecycle-policy-v1` starts when no valid active
supplier offer remains. This is a preview policy only; it does not change the
current product query, storefront, Scout index, robots response, or sitemap.

## Immediate Listing And Search Behavior

At day 0, future runtime behavior is: purchasing disabled; category listings
and internal search hidden; availability unavailable. The direct product page
remains HTTP 200, indexable, and eligible for the sitemap.

## First 60 Days

For complete days 1 through 59, the direct URL remains HTTP 200, indexable,
and in the sitemap. The product remains hidden from active discovery and
non-purchasable in the future runtime policy.

## Day 60 Noindex Follow

At 60 complete days without a valid active offer, the future archive state is
`archived_noindex`. The planned robots directive is `noindex, follow`; the
direct page remains HTTP 200.

## Sitemap Removal

The future policy excludes the archived-noindex product from the sitemap at day
60. No sitemap generation behavior is implemented in this phase.

## 24-Month Cold Archive Candidate

At 24 complete months with no active offer, the product becomes a
`cold_archive_candidate`. This is not a deletion, soft deletion, hard deletion,
or URL removal. The direct-page policy remains HTTP 200 pending a future,
explicitly approved policy.

## Reactivation

A valid active offer reappearance resets the zero-active-offer timestamp,
archive/noindex preview, sitemap eligibility, catalog visibility preview, and
purchase eligibility according to canonical availability. The reset is not
persisted in Phase 9C.6.5C.3D.

## No Automatic Product Deletion

Supplier absence, EOL, and long-term unavailability are never automatic
product-delete reasons. Any future manual deletion requires a separately
reviewed policy, SEO handling, dependency review, backup, and Super Admin
approval.
