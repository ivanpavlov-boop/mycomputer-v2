<?php

namespace App\Services\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\SupplierHumanDecision;
use App\Data\Suppliers\Onboarding\SupplierHumanDecisionRegister;
use App\Enums\Suppliers\Onboarding\SupplierHumanDecisionStatus;

final class SupplierHumanDecisionRegistry
{
    public const APCOM_REGISTER = 'apcom-human-decisions-v1';

    public const APCOM_REGISTER_V2 = 'apcom-human-decisions-v2';

    public const APCOM_REGISTER_V3 = 'apcom-human-decisions-v3';

    public function find(string $key): ?SupplierHumanDecisionRegister
    {
        return match ($key) {
            self::APCOM_REGISTER => $this->apcomV1(),
            self::APCOM_REGISTER_V2 => $this->apcomV2(),
            self::APCOM_REGISTER_V3 => $this->apcomV3(),
            default => null,
        };
    }

    public function apcomV1(): SupplierHumanDecisionRegister
    {
        return new SupplierHumanDecisionRegister(self::APCOM_REGISTER, 'apcom', [
            $this->decision('APCOM-ID-001', 'Supplier SKU identity', SupplierHumanDecisionStatus::Confirmed, 'xml.product.partno', 'source_to_staging_identity', 'exact source-to-staging identity', 'Authoritative source identity for preview-only reconciliation.', false, false),
            $this->decision('APCOM-SOURCE-001', 'Local XML source evidence', SupplierHumanDecisionStatus::Confirmed, 'xml.product source', 'pinned local source evidence', 'The operator must supply a pinned local SHA-256 fingerprint before a preview reads the source.', false, false, 'pinned_local_sha256', null),
            $this->decision('APCOM-ID-002', 'EAN or GTIN', SupplierHumanDecisionStatus::DiagnosticOnly, 'xml.product.ean', 'diagnostic_only', 'EAN can report discrepancies but cannot create, link, update, or merge records.', true, true),
            $this->decision('APCOM-LIFECYCLE-001', 'End of life state', SupplierHumanDecisionStatus::ReviewOnly, 'xml.product.eol', 'review_only', 'Observed lifecycle values require human review and cannot change lifecycle automatically.', true, true),
            $this->decision('APCOM-STOCK-001', 'Observed stock semantics', SupplierHumanDecisionStatus::Pending, 'xml.product.stock', 'unresolved', 'Observed numeric stock cannot be treated as quantity or binary availability without an approved semantic decision.', true, true),
            $this->decision('APCOM-QUANTITY-001', 'Catalog quantity mapping', SupplierHumanDecisionStatus::Pending, 'xml.product.stock to quantity', 'unresolved', 'No approved quantity source mapping exists.', true, true),
            $this->decision('APCOM-AVAILABILITY-001', 'Availability mapping', SupplierHumanDecisionStatus::Pending, 'xml.product.stock to availability', 'unresolved', 'No approved availability source mapping exists.', true, true),
            $this->decision('APCOM-MPN-001', 'Manufacturer part number', SupplierHumanDecisionStatus::Pending, 'xml.product.mpn', 'unresolved', 'No authoritative APCOM MPN field has been approved.', true, true),
            $this->decision('APCOM-PRICE-001', 'Selected commercial price', SupplierHumanDecisionStatus::Pending, 'xml.product.dac_price or xml.product.fd_price', 'unresolved', 'Price candidates are observed only; selection is a human commercial decision.', true, true),
            $this->decision('APCOM-CURRENCY-001', 'Currency semantics', SupplierHumanDecisionStatus::Pending, 'xml.product.currency', 'unresolved', 'No currency field or interpretation has been approved for execution.', true, true),
            $this->decision('APCOM-VAT-001', 'VAT treatment', SupplierHumanDecisionStatus::Pending, 'xml.product.vat', 'unresolved', 'VAT treatment remains a human commercial decision.', true, true),
            $this->decision('APCOM-GREEN-TAX-001', 'Green Tax treatment', SupplierHumanDecisionStatus::Pending, 'xml.product.greentax', 'unresolved', 'The authorized snapshot does not establish an executable Green Tax rule.', true, true),
            $this->decision('APCOM-SOURCE-ONLY-001', 'Source-only SKU classification', SupplierHumanDecisionStatus::Pending, 'exact supplier SKU source-only class', 'preview_only', 'Source-only rows are a preview classification, not an approved CREATE action.', true, true),
            $this->decision('APCOM-STAGING-ONLY-001', 'Staging-only classification', SupplierHumanDecisionStatus::Pending, 'exact supplier SKU staging-only class', 'staging_only_preview', 'Staging-only rows are classified only and cannot trigger removal or mutation.', true, true),
            $this->decision('APCOM-LINKED-STAGING-ONLY-001', 'Linked staging-only classification', SupplierHumanDecisionStatus::Pending, 'linked staging-only class', 'staging_only_preview', 'Linked staging-only rows require human review and cannot trigger unlinking.', true, true),
            $this->decision('APCOM-EOL-REVIEW-001', 'EOL review candidates', SupplierHumanDecisionStatus::ReviewOnly, 'xml.product.eol equals 1', 'review_only', 'EOL rows can be counted for review but cannot apply lifecycle changes.', true, true),
            $this->decision('APCOM-ZERO-PRICE-001', 'Zero price review candidates', SupplierHumanDecisionStatus::ReviewOnly, 'xml.product.dac_price or xml.product.fd_price equals 0', 'review_only', 'Zero commercial candidates require review and cannot select or write a price.', true, true),
            $this->decision('APCOM-PROHIBIT-AUTO-IMPORT-001', 'Automatic supplier import', SupplierHumanDecisionStatus::Prohibited, 'automatic import', 'prohibited', 'Automatic imports remain disabled for this design phase.', false, true),
            $this->decision('APCOM-PROHIBIT-SCHEDULE-001', 'Schedule enablement', SupplierHumanDecisionStatus::Prohibited, 'supplier schedule enablement', 'prohibited', 'Schedules must remain frozen.', false, true),
            $this->decision('APCOM-PROHIBIT-SYNC-ALL-001', 'Catalog Sync All', SupplierHumanDecisionStatus::Prohibited, 'Catalog Sync All', 'prohibited', 'Sync All is not approved.', false, true),
            $this->decision('APCOM-PROHIBIT-AUTO-SYNC-001', 'Automatic catalog sync', SupplierHumanDecisionStatus::Prohibited, 'automatic catalog sync', 'prohibited', 'Automatic catalog sync is not approved.', false, true),
            $this->decision('APCOM-PROHIBIT-UPDATE-SYNC-001', 'Catalog UPDATE sync', SupplierHumanDecisionStatus::Prohibited, 'Catalog UPDATE sync', 'prohibited', 'UPDATE sync is not part of this preview-only phase.', false, true),
            $this->decision('APCOM-PROHIBIT-IMAGE-IMPORT-001', 'Supplier image import', SupplierHumanDecisionStatus::Prohibited, 'supplier images', 'prohibited', 'Images are presence-only diagnostics and must not be imported.', false, true),
            $this->decision('APCOM-PROHIBIT-CONTENT-OVERWRITE-001', 'Supplier content overwrite', SupplierHumanDecisionStatus::Prohibited, 'product content fields', 'prohibited', 'Names, slugs, SEO, descriptions, images, categories, attributes, and workflow cannot be overwritten.', false, true),
        ]);
    }

    public function apcomV2(): SupplierHumanDecisionRegister
    {
        return new SupplierHumanDecisionRegister(self::APCOM_REGISTER_V2, 'apcom', [
            $this->decision('APCOM-ID-001', 'Supplier SKU identity', SupplierHumanDecisionStatus::Confirmed, 'xml.product.partno', 'source_to_staging_identity', 'Authoritative source identity for preview-only reconciliation.', false, false),
            $this->decision('APCOM-SOURCE-001', 'Local XML source evidence', SupplierHumanDecisionStatus::Confirmed, 'xml.product source', 'pinned_local_source_evidence', 'The operator must supply a pinned local SHA-256 fingerprint before a preview reads the source.', false, false, 'pinned_local_sha256'),
            $this->decision('APCOM-ID-002', 'EAN or GTIN', SupplierHumanDecisionStatus::DiagnosticOnly, 'xml.product.ean', 'diagnostic_only', 'EAN can report discrepancies but cannot create, link, update, or merge records.', true, true),
            $this->decision('APCOM-LIFECYCLE-001', 'End of life state', SupplierHumanDecisionStatus::Confirmed, 'xml.product.eol', 'canonical_supplier_lifecycle_status', 'eol=0 maps to active and eol=1 maps to eol; no destructive action is approved.', true, false, 'operator_confirmed_business_evidence', 'operator_portal_crosscheck_eol_positive_stock_orderable'),
            $this->decision('APCOM-STOCK-001', 'Supplier available quantity snapshot', SupplierHumanDecisionStatus::Confirmed, 'xml.product.stock', 'supplier_available_quantity_snapshot', 'Non-negative integer snapshot; 100 means 100 or more and exact public quantity remains prohibited.', true, false, 'operator_confirmed_business_evidence', 'operator_portal_crosscheck_stock_exact_quantity,operator_portal_crosscheck_stock_cap_100_plus'),
            $this->decision('APCOM-QUANTITY-001', 'Catalog quantity mapping', SupplierHumanDecisionStatus::ReviewOnly, 'xml.product.stock', 'internal_supplier_snapshot_metadata_only', 'The source snapshot is not approved for catalog quantity, public exact quantity, or guaranteed orderable quantity.', true, true),
            $this->decision('APCOM-AVAILABILITY-001', 'Availability mapping', SupplierHumanDecisionStatus::Confirmed, 'xml.product.stock with xml.product.eol', 'apcom-availability-policy-v1', 'Active stock maps 0 to on_request, 1-5 to limited, and 6+ to in_stock; exact public quantity stays hidden.', true, false, 'operator_confirmed_business_evidence', 'operator_portal_crosscheck_stock_zero_on_request,operator_approved_public_availability_policy'),
            $this->decision('APCOM-MPN-001', 'Manufacturer part number', SupplierHumanDecisionStatus::Pending, 'xml.product.mpn', 'unresolved', 'partno remains supplier SKU identity only and is not approved as manufacturer MPN.', true, true),
            $this->decision('APCOM-PRICE-001', 'Selected commercial price', SupplierHumanDecisionStatus::Confirmed, 'xml.product.fd_price', 'supplier_purchase_price', 'fd_price is the supplier purchase price without VAT; no staging or catalog price write is approved.', true, false, 'operator_confirmed_business_evidence', 'operator_portal_crosscheck_fd_price_exact_match'),
            $this->decision('APCOM-CURRENCY-001', 'Currency semantics', SupplierHumanDecisionStatus::Confirmed, 'operator-confirmed commercial interpretation', 'EUR', 'The approved supplier purchase price currency is EUR.', true, false, 'operator_confirmed_business_evidence', 'operator_confirmed_currency_eur'),
            $this->decision('APCOM-VAT-001', 'VAT treatment', SupplierHumanDecisionStatus::Confirmed, 'operator-confirmed commercial interpretation', 'exclusive', 'fd_price represents supplier purchase price without VAT.', true, false, 'operator_confirmed_business_evidence', 'operator_confirmed_vat_exclusive'),
            $this->decision('APCOM-GREEN-TAX-001', 'Green Tax treatment', SupplierHumanDecisionStatus::Confirmed, 'operator-confirmed commercial interpretation', 'included_in_fd_price', 'Green Tax is included in fd_price and is not added separately; contradictory future evidence requires review.', true, false, 'operator_confirmed_business_evidence', 'operator_confirmed_green_tax_included'),
            $this->decision('APCOM-SOURCE-ONLY-001', 'Source-only SKU classification', SupplierHumanDecisionStatus::Pending, 'exact supplier SKU source-only class', 'preview_only', 'Source-only rows are a preview classification, not an approved CREATE action.', true, true),
            $this->decision('APCOM-STAGING-ONLY-001', 'Staging-only classification', SupplierHumanDecisionStatus::Pending, 'exact supplier SKU staging-only class', 'staging_only_preview', 'Source absence does not establish EOL and cannot authorize deletion.', true, true),
            $this->decision('APCOM-LINKED-STAGING-ONLY-001', 'Linked staging-only classification', SupplierHumanDecisionStatus::Pending, 'linked staging-only class', 'staging_only_preview', 'Linked staging-only rows require human review and cannot trigger unlinking.', true, true),
            $this->decision('APCOM-EOL-REVIEW-001', 'EOL review candidates', SupplierHumanDecisionStatus::ReviewOnly, 'xml.product.eol equals 1', 'review_only', 'EOL populations remain human-review candidates even though lifecycle semantics are confirmed.', true, true),
            $this->decision('APCOM-ZERO-PRICE-001', 'Zero price review candidates', SupplierHumanDecisionStatus::ReviewOnly, 'xml.product.fd_price equals 0', 'review_only', 'Zero-price candidates require review and cannot select or write a price.', true, true),
            $this->decision('APCOM-PROHIBIT-AUTO-IMPORT-001', 'Automatic supplier import', SupplierHumanDecisionStatus::Prohibited, 'automatic import', 'prohibited', 'Automatic imports remain disabled for this design phase.', false, true),
            $this->decision('APCOM-PROHIBIT-SCHEDULE-001', 'Schedule enablement', SupplierHumanDecisionStatus::Prohibited, 'supplier schedule enablement', 'prohibited', 'Schedules must remain frozen.', false, true),
            $this->decision('APCOM-PROHIBIT-SYNC-ALL-001', 'Catalog Sync All', SupplierHumanDecisionStatus::Prohibited, 'Catalog Sync All', 'prohibited', 'Sync All is not approved.', false, true),
            $this->decision('APCOM-PROHIBIT-AUTO-SYNC-001', 'Automatic catalog sync', SupplierHumanDecisionStatus::Prohibited, 'automatic catalog sync', 'prohibited', 'Automatic catalog sync is not approved.', false, true),
            $this->decision('APCOM-PROHIBIT-UPDATE-SYNC-001', 'Catalog UPDATE sync', SupplierHumanDecisionStatus::Prohibited, 'Catalog UPDATE sync', 'prohibited', 'UPDATE sync is not part of this preview-only phase.', false, true),
            $this->decision('APCOM-PROHIBIT-IMAGE-IMPORT-001', 'Supplier image import', SupplierHumanDecisionStatus::Prohibited, 'supplier images', 'prohibited', 'Images are presence-only diagnostics and must not be imported.', false, true),
            $this->decision('APCOM-PROHIBIT-CONTENT-OVERWRITE-001', 'Supplier content overwrite', SupplierHumanDecisionStatus::Prohibited, 'product content fields', 'prohibited', 'Names, slugs, SEO, descriptions, images, categories, attributes, and workflow cannot be overwritten.', false, true),
        ], self::APCOM_REGISTER);
    }

    public function apcomV3(): SupplierHumanDecisionRegister
    {
        $decisions = array_map(function (SupplierHumanDecision $decision): SupplierHumanDecision {
            return match ($decision->decisionId) {
                'APCOM-STAGING-ONLY-001' => $this->decision(
                    'APCOM-STAGING-ONLY-001',
                    'Missing supplier offer lifecycle classification',
                    SupplierHumanDecisionStatus::Confirmed,
                    'qualified full snapshot absence',
                    'supplier_offer_missing_policy_preview',
                    'One missing snapshot is not EOL. Three qualified consecutive absences plus 48 hours may become a future supplier-offer-only eligibility signal; execution remains prohibited.',
                    true,
                    false,
                    'approved_missing_offer_policy',
                    SupplierOfferLifecyclePolicy::POLICY_KEY,
                ),
                'APCOM-LINKED-STAGING-ONLY-001' => $this->decision(
                    'APCOM-LINKED-STAGING-ONLY-001',
                    'Linked supplier offer lifecycle classification',
                    SupplierHumanDecisionStatus::Confirmed,
                    'linked supplier offer absent from qualified snapshot',
                    'supplier_offer_only_lifecycle_preview',
                    'A linked catalog product remains linked. No automatic unlink is allowed; only the supplier offer may become future deactivation-eligible after the approved policy threshold.',
                    true,
                    false,
                    'approved_linked_offer_lifecycle_policy',
                    CatalogOfferAggregationPolicy::POLICY_KEY,
                ),
                default => $decision,
            };
        }, $this->apcomV2()->decisions);

        $decisions[] = $this->decision(
            'APCOM-MISSING-OFFER-REAPPEARANCE-001',
            'Missing supplier offer reappearance',
            SupplierHumanDecisionStatus::Confirmed,
            'qualified full snapshot reappearance',
            'supplier_offer_reappearance_policy_preview',
            'A valid exact-SKU reappearance resets absence tracking and may be future reactivation-eligible. Zero price and identifier conflicts remain blocked; execution remains prohibited.',
            true,
            false,
            'approved_reappearance_policy',
            SupplierOfferReappearancePolicy::POLICY_KEY,
        );

        return new SupplierHumanDecisionRegister(self::APCOM_REGISTER_V3, 'apcom', $decisions, self::APCOM_REGISTER_V2);
    }

    private function decision(
        string $id,
        string $subject,
        SupplierHumanDecisionStatus $status,
        string $sourceFieldOrAction,
        string $role,
        string $rationale,
        bool $humanReviewRequired,
        bool $blockingDecision,
        ?string $evidenceRequirement = null,
        ?string $evidenceReference = null,
    ): SupplierHumanDecision {
        return new SupplierHumanDecision(
            decisionId: $id,
            subject: $subject,
            status: $status->value,
            sourceFieldOrAction: $sourceFieldOrAction,
            proposedRole: $role,
            approvedRole: $status === SupplierHumanDecisionStatus::Confirmed ? $role : null,
            evidenceRequirement: $evidenceRequirement,
            evidenceReference: $evidenceReference,
            rationale: $rationale,
            humanReviewRequired: $humanReviewRequired,
            automaticExecutionAllowed: false,
            catalogWriteAllowed: false,
            stagingWriteAllowed: false,
            profilePersistenceAllowed: false,
            blockingDecision: $blockingDecision,
            notes: null,
        );
    }
}
