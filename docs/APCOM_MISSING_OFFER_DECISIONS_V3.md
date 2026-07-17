# APCOM Missing Offer Decisions V3

## V1 And V2 Preservation

`apcom-human-decisions-v3` and `apcom-preview-feed-profile-v3` are additive
contracts. They explicitly supersede/reference v2 and do not rewrite
`apcom-human-decisions-v1`, `apcom-human-decisions-v2`,
`apcom-preview-feed-profile-v1`, `apcom-preview-feed-profile-v2`, or
`apcom-approved-business-semantics-v2`.

## Confirmed Staging-Only Policy

`APCOM-STAGING-ONLY-001` is confirmed for policy interpretation only: one
source absence is not EOL. Three qualified consecutive missing snapshots plus
48 hours can make only the APCOM offer future-deactivation eligible. Execution
in this phase is prohibited.

## Confirmed Linked Staging-Only Policy

`APCOM-LINKED-STAGING-ONLY-001` is confirmed: a linked catalog product remains
linked, automatic unlink is prohibited, and catalog availability depends on
all valid supplier offers.

## Reappearance Policy

`APCOM-MISSING-OFFER-REAPPEARANCE-001` is confirmed for preview: a valid,
exact-SKU, qualified reappearance resets missing tracking and may be future
reactivation eligible. Zero-price reappearance is review-only. Identifier
conflicts are blocked.

## Pending Or Review-Only Decisions

`APCOM-SOURCE-ONLY-001` remains pending; no automatic CREATE is authorized.
`APCOM-MPN-001` remains pending. `APCOM-ZERO-PRICE-001` remains review-only.
General stock/price snapshot freshness is pending and is distinct from the
48-hour missing-offer duration. Cart maximum quantity remains outside this
phase.

## Approval Gate And Execution

The v3 gate is `blocked_pending_implementation_approvals`. It has no approval
for import, profile persistence, supplier-offer lifecycle writes, product
visibility writes, schedules, Catalog Sync, retention cleanup, storefront
visibility, or sitemap/noindex implementation. No execution authorization is
granted.
