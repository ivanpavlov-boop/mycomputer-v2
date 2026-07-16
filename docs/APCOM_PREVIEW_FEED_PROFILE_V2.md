# APCOM Preview Feed Profile V2

## Profile

`apcom-preview-feed-profile-v2` is a new static, preview-only, read-only,
non-persisted, non-executable design. It preserves
`apcom-preview-feed-profile-v1` unchanged and depends on
`apcom-human-decisions-v2` plus `apcom-approved-business-semantics-v2`.

## Mappings

The profile uses `partno` for supplier SKU identity and treats EAN as a
diagnostic. It maps APCOM `stock` and `eol` only through the APCOM preview
policy, including the `100_or_more` cap and hidden public exact quantity.

`fd_price` is the previewed supplier purchase price in EUR without VAT.
`dac_price` remains diagnostic. Green Tax is included in `fd_price` and is not
added separately. MPN, missing-product handling, zero-price handling, snapshot
freshness, and cart limits remain unresolved.

## Canonical Statuses

The profile emits supplier-neutral availability, lifecycle, and computed public
status examples. It does not update the storefront or persist a public status.
Future multi-supplier aggregation remains a separate phase.

## Approval Gate

The profile emits a read-only approval gate. It remains
`blocked_pending_human_decisions`; import, persistence, schedule enablement,
and Catalog Sync approval are false.

## Guarantees

The profile has no apply, persist, approve, activate, import, sync, create,
update, delete, link, unlink, enable, schedule, or image control. It does not
run an operational preview in this phase, does not import, and does not modify
suppliers, staging, catalog data, mappings, links, images, or schedules.

The C3C operational v2 preview has not run.

The next phase requires a separately approved design. UPDATE remains disabled,
Sync All remains disabled, and automatic sync remains disabled.
