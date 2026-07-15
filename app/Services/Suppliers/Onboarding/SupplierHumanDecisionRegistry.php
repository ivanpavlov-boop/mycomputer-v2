<?php

namespace App\Services\Suppliers\Onboarding;

use App\Data\Suppliers\Onboarding\SupplierHumanDecision;
use App\Data\Suppliers\Onboarding\SupplierHumanDecisionRegister;
use App\Enums\Suppliers\Onboarding\SupplierHumanDecisionStatus;

final class SupplierHumanDecisionRegistry
{
    public const APCOM_REGISTER = 'apcom-human-decisions-v1';

    public function find(string $key): ?SupplierHumanDecisionRegister
    {
        return $key === self::APCOM_REGISTER ? $this->apcomV1() : null;
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
