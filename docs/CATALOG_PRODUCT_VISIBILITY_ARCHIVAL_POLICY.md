# Catalog Product Visibility And Archival Policy

## Multi-Supplier Aggregation

`catalog-offer-aggregation-policy-v1` evaluates all valid supplier offers for
one catalog product. A single missing, inactive, or EOL offer cannot deactivate
the catalog product when another valid offer is `in_stock`, `limited`,
`on_request`, or `last_units`. Invalid or zero-price offers do not override a
valid active offer. The confirmed-missing offer may receive
`would_deactivate_offer`, while the product recommendation remains
`keep_active`.

## Zero Active Offers

`catalog-product-visibility-lifecycle-policy-v1` starts when no valid active
supplier offer remains because continuously provable confirmed-missing
lifecycle evidence has removed every active alternative. Out-of-stock or
invalid-price offers alone do not start the archival clock. If the evidence
cannot derive an uninterrupted no-active-offer interval, the result is
`manual_review`. This is a preview policy only; it does not change the current
product query, storefront, Scout index, robots response, or sitemap.

The current synthetic policy may use `evaluated_at` as a bounded scenario
fallback when no zero-active-offer timestamp is supplied. That fallback is not
valid operational evidence and must not be reused by the first operational
preview.

## Immediate Listing And Search Behavior

At day 0, the preview may recommend `would_mark_unavailable` only when no
qualifying sellable supplier offer remains. This remains a recommendation and
does not disable purchasing, hide listings or search results, or change current
availability. Under the planned behavior, the direct product page remains HTTP 200, indexable,
and eligible for the sitemap.

## First 60 Days

For complete days 1 through 59, the direct URL remains HTTP 200, indexable,
and in the sitemap. The product remains hidden from active discovery and
non-purchasable in the future runtime policy.

## Day 60 Noindex Follow

At 60 complete days without a valid active offer, the future archive state is
`archived_noindex`, represented only by the
`would_mark_archived_noindex` recommendation. The planned robots directive is
`noindex, follow`; the direct page remains HTTP 200. No Product status,
publication, visibility, search, robots, or sitemap value changes in this
phase.

## Sitemap Removal

The future policy excludes the archived-noindex product from the sitemap at day
60. No sitemap generation behavior is implemented in this phase.

## 24-Month Cold Archive Candidate

At 24 complete months with no active offer, the product becomes a
`cold_archive_candidate`, represented only by the
`would_mark_cold_archive_candidate` recommendation. This is not a deletion,
soft deletion, hard deletion, or URL removal. The direct-page policy remains
HTTP 200 pending a future, explicitly approved policy.

## Reactivation

A valid active offer reappearance resets the zero-active-offer timestamp,
archive/noindex preview, sitemap eligibility, catalog visibility preview, and
purchase eligibility according to canonical availability. The reset is not
persisted in Phase 9C.6.5C.3D.

## Manual And Workflow Protection

Manually created products without supplier offers are outside this lifecycle
preview. An existing explicit manual override produces `manual_review`. When
the repository cannot authoritatively distinguish a protected manually
maintained product, evaluation fails closed as `manual_review`; no heuristic
may infer that the product is safe to archive.

Draft, pending-review, and approved-but-unpublished products may have offer
evidence evaluated, but receive no public archival or visibility
recommendation. Soft-deleted products and disabled or deleted suppliers are
excluded or fail closed according to existing repository conventions. Product
names, slugs, descriptions, SEO, images, categories, attributes, and brands are
outside this policy.

## No Automatic Product Deletion

Supplier absence, EOL, and long-term unavailability are never automatic
product-delete reasons. Any future manual deletion requires a separately
reviewed policy, SEO handling, dependency review, backup, and Super Admin
approval.
