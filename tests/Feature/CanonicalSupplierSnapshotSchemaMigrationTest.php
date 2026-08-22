<?php

namespace Tests\Feature;

use Database\Migrations\Support\CanonicalSupplierSnapshotSchema;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PDO;
use PDOException;
use ReflectionClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

require_once __DIR__.'/../../database/migrations/support/CanonicalSupplierSnapshotSchema.php';

final class CanonicalSupplierSnapshotSchemaMigrationTest extends TestCase
{
    /** @var array<string, array<string, string>> */
    private const EXPECTED_CHECKS = [
        'supplier_import_cohort_authorization_members' => [
            'chk_import_cohort_auth_supplier_sku_hash' => '((length(`supplier_sku_hash`) = 64) and regexp_like(`supplier_sku_hash`,_ascii\\\'^[0-9a-f]{64}$\\\',_utf8mb4\\\'c\\\'))',
        ],
        'supplier_import_dispatch_alert_intents' => [
            'chk_import_dispatch_alert_attempt_bound' => '((`attempt_count` >= 0) and (`attempt_count` <= 8))',
            'chk_import_dispatch_alert_delivery_owner_tuple' => '(((`delivery_owner_token_hash` is null) and (`delivery_lease_acquired_at` is null) and (`delivery_lease_expires_at` is null)) or ((`delivery_owner_token_hash` is not null) and (length(`delivery_owner_token_hash`) = 64) and regexp_like(`delivery_owner_token_hash`,_ascii\\\'^[0-9a-f]{64}$\\\',_utf8mb4\\\'c\\\') and (`delivery_lease_acquired_at` is not null) and (`delivery_lease_expires_at` is not null) and (`delivery_lease_acquired_at` < `delivery_lease_expires_at`)))',
            'chk_import_dispatch_alert_identity' => '((length(`alert_identity`) = 64) and regexp_like(`alert_identity`,_ascii\\\'^[0-9a-f]{64}$\\\',_utf8mb4\\\'c\\\'))',
            'chk_import_dispatch_alert_schema_type' => '((cast(`schema_version` as char charset binary) = cast(_ascii\\\'supplier-import-dispatch-alert-v1\\\' as char charset binary)) and (length(`schema_version`) = 33) and (cast(`alert_type` as char charset binary) = cast(_ascii\\\'dispatch_watchdog_overdue\\\' as char charset binary)) and (length(`alert_type`) = 25) and (json_type(`payload`) = _utf8mb4\\\'OBJECT\\\'))',
            'chk_import_dispatch_alert_severity_bucket' => '(((cast(`severity` as char charset binary) = cast(_ascii\\\'warning\\\' as char charset binary)) and (`critical_bucket` is null)) or ((cast(`severity` as char charset binary) = cast(_ascii\\\'critical\\\' as char charset binary)) and (`critical_bucket` is not null)))',
            'chk_import_dispatch_alert_state' => '(cast(`delivery_state` as char charset binary) in (cast(_ascii\\\'pending\\\' as char charset binary),cast(_ascii\\\'delivering\\\' as char charset binary),cast(_ascii\\\'acknowledged\\\' as char charset binary),cast(_ascii\\\'permanent_failed\\\' as char charset binary),cast(_ascii\\\'delivery_outcome_unknown_exhausted\\\' as char charset binary)))',
            'chk_import_dispatch_alert_state_tuple' => '(((`delivery_state` = _utf8mb4\\\'pending\\\') and (`delivery_owner_token_hash` is null) and (`delivery_lease_acquired_at` is null) and (`delivery_lease_expires_at` is null) and (`next_attempt_at` is not null) and (`acknowledged_at` is null)) or ((`delivery_state` = _utf8mb4\\\'delivering\\\') and (`delivery_owner_token_hash` is not null) and (`delivery_lease_acquired_at` is not null) and (`delivery_lease_expires_at` is not null) and (`next_attempt_at` is null) and (`acknowledged_at` is null) and (`attempt_count` between 1 and 8)) or ((`delivery_state` = _utf8mb4\\\'acknowledged\\\') and (`delivery_owner_token_hash` is null) and (`delivery_lease_acquired_at` is null) and (`delivery_lease_expires_at` is null) and (`next_attempt_at` is null) and (`acknowledged_at` is not null) and (`last_failure_code` is null)) or ((`delivery_state` = _utf8mb4\\\'permanent_failed\\\') and (`delivery_owner_token_hash` is null) and (`delivery_lease_acquired_at` is null) and (`delivery_lease_expires_at` is null) and (`next_attempt_at` is null) and (`acknowledged_at` is null) and (`attempt_count` between 1 and 8) and (`last_failure_code` is not null)) or ((`delivery_state` = _utf8mb4\\\'delivery_outcome_unknown_exhausted\\\') and (`delivery_owner_token_hash` is null) and (`delivery_lease_acquired_at` is null) and (`delivery_lease_expires_at` is null) and (`next_attempt_at` is null) and (`acknowledged_at` is null) and (`attempt_count` = 8) and (cast(`last_failure_code` as char charset binary) = cast(_ascii\\\'alert_delivery_outcome_unknown_exhausted\\\' as char charset binary))))',
        ],
        'supplier_import_dispatch_monitor_health' => [
            'chk_import_dispatch_monitor_generation' => '(`last_successful_monitor_generation` <= `monitor_generation`)',
            'chk_import_dispatch_monitor_identity' => '((cast(`monitor_identity` as char charset binary) = cast(_ascii\\\'supplier-import-dispatch-watchdog-v1\\\' as char charset binary)) and (length(`monitor_identity`) = 36) and (cast(`observer_identity` as char charset binary) = cast(_ascii\\\'supplier-import-dispatch-observer-v1\\\' as char charset binary)) and (length(`observer_identity`) = 36))',
            'chk_import_dispatch_monitor_integrity_state' => '(cast(`integrity_state` as char charset binary) in (cast(_ascii\\\'healthy\\\' as char charset binary),cast(_ascii\\\'stale\\\' as char charset binary),cast(_ascii\\\'failed\\\' as char charset binary),cast(_ascii\\\'unknown\\\' as char charset binary)))',
            'chk_import_dispatch_monitor_observer_tuple' => '(((`observer_sequence` = 0) and (`observed_monitor_generation` = 0) and (`observed_cycle_sequence` = 0) and (`last_successful_observer_at` is null)) or ((`observer_sequence` > 0) and (`observed_monitor_generation` > 0) and (`observed_cycle_sequence` > 0) and (`last_successful_observer_at` is not null) and (`observed_monitor_generation` <= `last_successful_monitor_generation`) and (`observed_cycle_sequence` <= `cycle_sequence`)))',
            'chk_import_dispatch_monitor_owner_tuple' => '(((`monitor_owner_token_hash` is null) and (`monitor_lease_acquired_at` is null) and (`monitor_lease_expires_at` is null)) or ((`monitor_owner_token_hash` is not null) and (length(`monitor_owner_token_hash`) = 64) and regexp_like(`monitor_owner_token_hash`,_ascii\\\'^[0-9a-f]{64}$\\\',_utf8mb4\\\'c\\\') and (`monitor_lease_acquired_at` is not null) and (`monitor_lease_expires_at` is not null) and (`monitor_lease_acquired_at` < `monitor_lease_expires_at`)))',
            'chk_import_dispatch_monitor_singleton' => '(`id` = 1)',
            'chk_import_dispatch_monitor_stored_healthy' => '((`integrity_state` <> _utf8mb4\\\'healthy\\\') or ((`cycle_sequence` > 0) and (`last_successful_monitor_generation` > 0) and (`last_successful_cycle_at` is not null) and (`last_successful_sink_health_at` is not null) and (`last_successful_cycle_at` = `last_successful_sink_health_at`) and (`last_successful_sink_contract_key` is not null) and (`last_failure_code` is null)))',
            'chk_import_dispatch_monitor_success_tuple' => '(((`cycle_sequence` = 0) and (`last_successful_monitor_generation` = 0) and (`last_successful_cycle_at` is null) and (`last_successful_sink_health_at` is null) and (`last_successful_sink_contract_key` is null)) or ((`cycle_sequence` > 0) and (`last_successful_monitor_generation` > 0) and (`last_successful_cycle_at` is not null) and (`last_successful_sink_health_at` is not null) and (`last_successful_cycle_at` = `last_successful_sink_health_at`) and (`last_successful_sink_contract_key` is not null)))',
        ],
        'supplier_import_dispatch_outbox' => [
            'chk_import_outbox_attempt_bound' => '((`attempt_count` >= 0) and (`attempt_count` <= 8))',
            'chk_import_outbox_delivery_attempt_bound' => '((`delivery_attempt_count` >= 0) and (`delivery_attempt_count` <= 8))',
            'chk_import_outbox_event_type' => '(cast(`event_type` as char charset binary) = cast(_ascii\\\'initial_dispatch\\\' as char charset binary))',
            'chk_import_outbox_job_type' => '(cast(`job_type` as char charset binary) in (cast(_ascii\\\'run_supplier_import\\\' as char charset binary),cast(_ascii\\\'process_xml_supplier_feed\\\' as char charset binary)))',
            'chk_import_outbox_lease_hash' => '((`lease_token_hash` is null) or ((length(`lease_token_hash`) = 64) and regexp_like(`lease_token_hash`,_ascii\\\'^[0-9a-f]{64}$\\\',_utf8mb4\\\'c\\\')))',
            'chk_import_outbox_lease_tuple' => '(((`lease_owner_key` is null) and (`lease_token_hash` is null) and (`leased_at` is null) and (`lease_expires_at` is null)) or ((`lease_owner_key` is not null) and (`lease_token_hash` is not null) and (`leased_at` is not null) and (`lease_expires_at` is not null)))',
            'chk_import_outbox_logical_key' => '((length(`logical_execution_key`) = 64) and regexp_like(`logical_execution_key`,_ascii\\\'^[0-9a-f]{64}$\\\',_utf8mb4\\\'c\\\'))',
            'chk_import_outbox_payload_hash' => '((length(`dispatch_payload_hash`) = 64) and regexp_like(`dispatch_payload_hash`,_ascii\\\'^[0-9a-f]{64}$\\\',_utf8mb4\\\'c\\\'))',
            'chk_import_outbox_payload_object' => '(json_type(`dispatch_payload`) = _utf8mb4\\\'OBJECT\\\')',
            'chk_import_outbox_publication_attempt_state' => '(cast(`publication_attempt_state` as char charset binary) in (cast(_ascii\\\'none\\\' as char charset binary),cast(_ascii\\\'reserved\\\' as char charset binary),cast(_ascii\\\'external_fence_installed\\\' as char charset binary),cast(_ascii\\\'call_boundary_entered\\\' as char charset binary),cast(_ascii\\\'durable_success\\\' as char charset binary),cast(_ascii\\\'durable_failure\\\' as char charset binary),cast(_ascii\\\'outcome_unknown\\\' as char charset binary)))',
            'chk_import_outbox_publication_attempt_tuple' => '(((`publication_attempt_state` = _ascii\\\'none\\\') and (`publication_attempt_generation` = 0) and (`attempt_count` = 0) and (`publication_attempt_token_hash` is null) and (`publication_attempt_reserved_at` is null) and (`publication_attempt_lease_expires_at` is null) and (`publication_external_fence_installed_at` is null) and (`publication_call_boundary_at` is null) and (`publication_attempt_resolved_at` is null)) or ((`publication_attempt_state` = _ascii\\\'reserved\\\') and (`publication_attempt_generation` > 0) and (`attempt_count` > 0) and (`publication_attempt_token_hash` is not null) and (`publication_attempt_reserved_at` is not null) and (`publication_attempt_lease_expires_at` > `publication_attempt_reserved_at`) and (`publication_external_fence_installed_at` is null) and (`publication_call_boundary_at` is null) and (`publication_attempt_resolved_at` is null)) or ((`publication_attempt_state` = _ascii\\\'external_fence_installed\\\') and (`publication_attempt_generation` > 0) and (`attempt_count` > 0) and (`publication_attempt_token_hash` is not null) and (`publication_attempt_reserved_at` is not null) and (`publication_attempt_lease_expires_at` > `publication_attempt_reserved_at`) and (`publication_external_fence_installed_at` >= `publication_attempt_reserved_at`) and (`publication_call_boundary_at` is null) and (`publication_attempt_resolved_at` is null)) or ((`publication_attempt_state` = _ascii\\\'call_boundary_entered\\\') and (`publication_attempt_generation` > 0) and (`attempt_count` > 0) and (`publication_attempt_token_hash` is not null) and (`publication_attempt_reserved_at` is not null) and (`publication_attempt_lease_expires_at` > `publication_attempt_reserved_at`) and (`publication_external_fence_installed_at` >= `publication_attempt_reserved_at`) and (`publication_call_boundary_at` >= `publication_external_fence_installed_at`) and (`publication_attempt_resolved_at` is null)) or ((`publication_attempt_state` in (_ascii\\\'durable_success\\\',_ascii\\\'durable_failure\\\')) and (`publication_attempt_generation` > 0) and (`attempt_count` > 0) and (`publication_attempt_token_hash` is null) and (`publication_attempt_reserved_at` is not null) and (`publication_attempt_lease_expires_at` > `publication_attempt_reserved_at`) and (`publication_external_fence_installed_at` >= `publication_attempt_reserved_at`) and (`publication_call_boundary_at` >= `publication_external_fence_installed_at`) and (`publication_attempt_resolved_at` >= `publication_call_boundary_at`)) or ((`publication_attempt_state` = _ascii\\\'outcome_unknown\\\') and (`publication_attempt_generation` > 0) and (`attempt_count` > 0) and (`publication_attempt_token_hash` is null) and (`publication_attempt_reserved_at` is not null) and (`publication_attempt_lease_expires_at` > `publication_attempt_reserved_at`) and ((`publication_external_fence_installed_at` is null) or (`publication_external_fence_installed_at` >= `publication_attempt_reserved_at`)) and ((`publication_call_boundary_at` is null) or ((`publication_external_fence_installed_at` is not null) and (`publication_call_boundary_at` >= `publication_external_fence_installed_at`))) and (`publication_attempt_resolved_at` >= `publication_attempt_reserved_at`)))',
            'chk_import_outbox_publication_token_hash' => '((`publication_attempt_token_hash` is null) or ((length(`publication_attempt_token_hash`) = 64) and regexp_like(`publication_attempt_token_hash`,_ascii\\\'^[0-9a-f]{64}$\\\',_utf8mb4\\\'c\\\')))',
            'chk_import_outbox_publish_tuple' => '(((`published_at` is null) and (`last_published_at` is null)) or ((`published_at` is not null) and (`last_published_at` is not null)))',
            'chk_import_outbox_state' => '(`state` in (_ascii\\\'pending\\\',_ascii\\\'leased\\\',_ascii\\\'published\\\',_ascii\\\'recovery_required\\\',_ascii\\\'terminal_failed\\\'))',
            'chk_import_outbox_state_fields' => '(((`state` = _ascii\\\'pending\\\') and (`lease_owner_key` is null) and (`lease_token_hash` is null) and (`leased_at` is null) and (`lease_expires_at` is null) and (`published_at` is null) and (`last_published_at` is null) and (`delivery_watchdog_at` is null) and (`recovery_required_at` is null) and (`recovery_reason_code` is null) and (`terminal_at` is null) and (`terminal_failure_reason_code` is null)) or ((`state` = _ascii\\\'leased\\\') and (`lease_owner_key` is not null) and (`lease_token_hash` is not null) and (`leased_at` is not null) and (`lease_expires_at` is not null) and (`delivery_watchdog_at` is null) and (`recovery_required_at` is null) and (`recovery_reason_code` is null) and (`terminal_at` is null) and (`terminal_failure_reason_code` is null)) or ((`state` = _ascii\\\'published\\\') and (`lease_owner_key` is null) and (`lease_token_hash` is null) and (`leased_at` is null) and (`lease_expires_at` is null) and (`published_at` is not null) and (`last_published_at` is not null) and (`recovery_required_at` is null) and (`recovery_reason_code` is null) and (`terminal_at` is null) and (`terminal_failure_reason_code` is null)) or ((`state` = _ascii\\\'recovery_required\\\') and (`lease_owner_key` is null) and (`lease_token_hash` is null) and (`leased_at` is null) and (`lease_expires_at` is null) and (`published_at` is not null) and (`last_published_at` is not null) and (`delivery_watchdog_at` is null) and (`recovery_required_at` is not null) and (`recovery_reason_code` is not null) and (`terminal_at` is null) and (`terminal_failure_reason_code` is null)) or ((`state` = _ascii\\\'terminal_failed\\\') and (`lease_owner_key` is null) and (`lease_token_hash` is null) and (`leased_at` is null) and (`lease_expires_at` is null) and (`delivery_watchdog_at` is null) and (`recovery_required_at` is null) and (`recovery_reason_code` is null) and (`terminal_at` is not null) and (`terminal_failure_reason_code` is not null)))',
            'chk_import_outbox_terminal_attempt' => '((`terminal_failure_reason_code` <> _ascii\\\'dispatch_publication_attempts_exhausted\\\') or (`attempt_count` = 8))',
            'chk_import_outbox_timestamp_order' => '((`transport_deadline_at` > `created_at`) and ((`leased_at` is null) or ((`leased_at` >= `created_at`) and (`leased_at` < `lease_expires_at`))) and ((`next_attempt_at` is null) or (`next_attempt_at` >= `created_at`)) and ((`published_at` is null) or (`published_at` >= `created_at`)) and ((`last_published_at` is null) or (`last_published_at` >= `published_at`)) and ((`delivery_watchdog_at` is null) or (`delivery_watchdog_at` >= `last_published_at`)) and ((`recovery_required_at` is null) or (`recovery_required_at` >= `created_at`)) and ((`terminal_at` is null) or (`terminal_at` >= `created_at`)))',
            'chk_import_outbox_transport_deadline' => '(`transport_deadline_at` = (`created_at` + interval 24 hour))',
            'chk_import_outbox_watchdog_state' => '((`delivery_watchdog_at` is null) or (`state` = _ascii\\\'published\\\'))',
        ],
        'supplier_import_dispatch_recovery_authorizations' => [
            'chk_import_recovery_auth_action' => '(cast(`authorization_action` as char charset binary) in (cast(_ascii\\\'republish_same_key\\\' as char charset binary),cast(_ascii\\\'recover_expired_queued_ownership\\\' as char charset binary),cast(_ascii\\\'terminalize_stale_dispatch\\\' as char charset binary),cast(_ascii\\\'terminalize_publication_mismatch\\\' as char charset binary),cast(_ascii\\\'terminalize_abandoned_processing\\\' as char charset binary)))',
            'chk_import_recovery_auth_expected_fingerprint' => '((length(`expected_state_fingerprint`) = 64) and regexp_like(`expected_state_fingerprint`,_ascii\\\'^[0-9a-f]{64}$\\\',_utf8mb4\\\'c\\\'))',
            'chk_import_recovery_auth_expiry' => '(`expires_at` = (`authorized_at` + interval 900 second))',
            'chk_import_recovery_auth_logical_key' => '((length(`logical_execution_key`) = 64) and regexp_like(`logical_execution_key`,_ascii\\\'^[0-9a-f]{64}$\\\',_utf8mb4\\\'c\\\'))',
            'chk_import_recovery_auth_nonce_hash' => '((length(`authorization_nonce_hash`) = 64) and regexp_like(`authorization_nonce_hash`,_ascii\\\'^[0-9a-f]{64}$\\\',_utf8mb4\\\'c\\\'))',
            'chk_import_recovery_auth_parent_id' => '(`target_parent_id` > 0)',
            'chk_import_recovery_auth_parent_type' => '(cast(`target_parent_type` as char charset binary) in (cast(_ascii\\\'supplier_import_run\\\' as char charset binary),cast(_ascii\\\'supplier_feed\\\' as char charset binary)))',
        ],
        'supplier_import_dispatch_recovery_results' => [
            'chk_import_recovery_result_action_event_code' => '(((cast(`event_kind` as char charset binary) = cast(_ascii\\\'started\\\' as char charset binary)) and (cast(`canonical_result_code` as char charset binary) = cast(_ascii\\\'authorization_attempt_started\\\' as char charset binary))) or ((cast(`event_kind` as char charset binary) = cast(_ascii\\\'rejected\\\' as char charset binary)) and (cast(`canonical_result_code` as char charset binary) in (cast(_ascii\\\'authorization_expired\\\' as char charset binary),cast(_ascii\\\'state_fingerprint_mismatch\\\' as char charset binary),cast(_ascii\\\'resume_state_fingerprint_mismatch\\\' as char charset binary),cast(_ascii\\\'state_conflict\\\' as char charset binary),cast(_ascii\\\'noncanonical_parent\\\' as char charset binary),cast(_ascii\\\'action_not_permitted\\\' as char charset binary),cast(_ascii\\\'response_window_expired\\\' as char charset binary),cast(_ascii\\\'monitor_integrity_not_healthy\\\' as char charset binary)))) or ((cast(`event_kind` as char charset binary) = cast(_ascii\\\'already_terminal\\\' as char charset binary)) and (cast(`canonical_result_code` as char charset binary) = cast(_ascii\\\'already_terminal_noop\\\' as char charset binary))) or ((cast(`authorization_action` as char charset binary) = cast(_ascii\\\'republish_same_key\\\' as char charset binary)) and (((cast(`event_kind` as char charset binary) = cast(_ascii\\\'republish_succeeded\\\' as char charset binary)) and (cast(`canonical_result_code` as char charset binary) = cast(_ascii\\\'dispatch_republished_same_key\\\' as char charset binary))) or ((cast(`event_kind` as char charset binary) = cast(_ascii\\\'publish_failed\\\' as char charset binary)) and (cast(`canonical_result_code` as char charset binary) in (cast(_ascii\\\'dispatch_publication_failed\\\' as char charset binary),cast(_ascii\\\'dispatch_publication_attempts_exhausted\\\' as char charset binary)))) or ((cast(`event_kind` as char charset binary) = cast(_ascii\\\'action_stopped\\\' as char charset binary)) and (cast(`canonical_result_code` as char charset binary) in (cast(_ascii\\\'republish_delivery_budget_exhausted_after_start\\\' as char charset binary),cast(_ascii\\\'republish_transport_deadline_expired_after_start\\\' as char charset binary),cast(_ascii\\\'republish_response_window_expired_after_start\\\' as char charset binary),cast(_ascii\\\'monitor_integrity_not_healthy_after_start\\\' as char charset binary),cast(_ascii\\\'republish_state_conflict_after_start\\\' as char charset binary)))))) or ((cast(`authorization_action` as char charset binary) = cast(_ascii\\\'recover_expired_queued_ownership\\\' as char charset binary)) and (cast(`event_kind` as char charset binary) = cast(_ascii\\\'ownership_recovery_succeeded\\\' as char charset binary)) and (cast(`canonical_result_code` as char charset binary) = cast(_ascii\\\'queued_ownership_lease_expired\\\' as char charset binary))) or ((cast(`authorization_action` as char charset binary) = cast(_ascii\\\'terminalize_stale_dispatch\\\' as char charset binary)) and (cast(`event_kind` as char charset binary) = cast(_ascii\\\'terminalization_succeeded\\\' as char charset binary)) and (cast(`canonical_result_code` as char charset binary) in (cast(_ascii\\\'transport_delivery_budget_exhausted\\\' as char charset binary),cast(_ascii\\\'transport_deadline_expired\\\' as char charset binary),cast(_ascii\\\'dispatch_watchdog_operator_terminalized\\\' as char charset binary),cast(_ascii\\\'dispatch_watchdog_response_expired\\\' as char charset binary),cast(_ascii\\\'dispatch_publication_attempts_exhausted\\\' as char charset binary)))) or ((cast(`authorization_action` as char charset binary) = cast(_ascii\\\'terminalize_publication_mismatch\\\' as char charset binary)) and (cast(`event_kind` as char charset binary) = cast(_ascii\\\'terminalization_succeeded\\\' as char charset binary)) and (cast(`canonical_result_code` as char charset binary) = cast(_ascii\\\'dispatch_publication_mismatch\\\' as char charset binary))) or ((cast(`authorization_action` as char charset binary) = cast(_ascii\\\'terminalize_abandoned_processing\\\' as char charset binary)) and (cast(`event_kind` as char charset binary) = cast(_ascii\\\'terminalization_succeeded\\\' as char charset binary)) and (cast(`canonical_result_code` as char charset binary) = cast(_ascii\\\'processing_lease_abandoned\\\' as char charset binary))))',
            'chk_import_recovery_result_event' => '(cast(`event_kind` as char charset binary) in (cast(_ascii\\\'started\\\' as char charset binary),cast(_ascii\\\'republish_succeeded\\\' as char charset binary),cast(_ascii\\\'terminalization_succeeded\\\' as char charset binary),cast(_ascii\\\'ownership_recovery_succeeded\\\' as char charset binary),cast(_ascii\\\'publish_failed\\\' as char charset binary),cast(_ascii\\\'action_stopped\\\' as char charset binary),cast(_ascii\\\'rejected\\\' as char charset binary),cast(_ascii\\\'already_terminal\\\' as char charset binary)))',
            'chk_import_recovery_result_fingerprint' => '((length(`result_fingerprint`) = 64) and regexp_like(`result_fingerprint`,_ascii\\\'^[0-9a-f]{64}$\\\',_utf8mb4\\\'c\\\'))',
            'chk_import_recovery_result_resume_fingerprint' => '(((cast(`event_kind` as char charset binary) = cast(_ascii\\\'started\\\' as char charset binary)) and (cast(`authorization_action` as char charset binary) = cast(_ascii\\\'republish_same_key\\\' as char charset binary)) and (length(`resume_state_fingerprint`) = 64) and regexp_like(`resume_state_fingerprint`,_ascii\\\'^[0-9a-f]{64}$\\\',_utf8mb4\\\'c\\\')) or (((cast(`event_kind` as char charset binary) <> cast(_ascii\\\'started\\\' as char charset binary)) or (cast(`authorization_action` as char charset binary) <> cast(_ascii\\\'republish_same_key\\\' as char charset binary))) and (`resume_state_fingerprint` is null)))',
            'chk_import_recovery_result_sequence' => '(((cast(`event_kind` as char charset binary) in (cast(_ascii\\\'started\\\' as char charset binary),cast(_ascii\\\'rejected\\\' as char charset binary),cast(_ascii\\\'already_terminal\\\' as char charset binary))) and (`event_sequence` = 1)) or ((cast(`event_kind` as char charset binary) in (cast(_ascii\\\'republish_succeeded\\\' as char charset binary),cast(_ascii\\\'terminalization_succeeded\\\' as char charset binary),cast(_ascii\\\'ownership_recovery_succeeded\\\' as char charset binary),cast(_ascii\\\'publish_failed\\\' as char charset binary),cast(_ascii\\\'action_stopped\\\' as char charset binary))) and (`event_sequence` = 2)))',
        ],
        'supplier_import_execution_claims' => [
            'chk_import_claim_attempt_hash' => '((`active_attempt_token_hash` is null) or ((length(`active_attempt_token_hash`) = 64) and regexp_like(`active_attempt_token_hash`,_ascii\\\'^[0-9a-f]{64}$\\\',_utf8mb4\\\'c\\\')))',
            'chk_import_claim_attempt_time_order' => '((`claimed_at` is null) or (`claimed_at` < `attempt_lease_expires_at`))',
            'chk_import_claim_attempt_tuple' => '(((`active_attempt_token_hash` is null) and (`claimed_at` is null) and (`attempt_lease_expires_at` is null)) or ((`active_attempt_token_hash` is not null) and (`claimed_at` is not null) and (`attempt_lease_expires_at` is not null)))',
            'chk_import_claim_cohort_auth_time' => '((`cohort_authorized_at` is null) or ((`allocated_at` is not null) and (`cohort_authorized_at` >= `allocated_at`)))',
            'chk_import_claim_cohort_auth_version' => '((`cohort_authorization_version` is null) or (`cohort_authorization_version` = _ascii\\\'supplier_offer_cohort_v1\\\'))',
            'chk_import_claim_cohort_authorization_tuple' => '(((`cohort_authorization_version` is null) and (`cohort_authorized_at` is null) and (`cohort_seed_count` is null) and (`cohort_seed_fingerprint` is null)) or ((`cohort_authorization_version` is not null) and (`cohort_authorized_at` is not null) and (`cohort_seed_count` is not null) and (`cohort_seed_fingerprint` is not null)))',
            'chk_import_claim_cohort_seed_hash' => '((`cohort_seed_fingerprint` is null) or ((length(`cohort_seed_fingerprint`) = 64) and regexp_like(`cohort_seed_fingerprint`,_ascii\\\'^[0-9a-f]{64}$\\\',_utf8mb4\\\'c\\\')))',
            'chk_import_claim_processing_marker' => '((`processing_started_at` is null) or (`state` in (_ascii\\\'processing\\\',_ascii\\\'terminal_qualified\\\',_ascii\\\'terminal_frozen\\\',_ascii\\\'terminal_failed\\\')))',
            'chk_import_claim_processing_owner' => '((`state` <> _ascii\\\'processing\\\') or ((`supplier_feed_id` is not null) and (`import_job_id` is not null) and (`allocated_at` is not null) and (`import_history_id` is not null) and (`source_fingerprint` is not null) and (`cohort_authorization_version` is not null) and (`cohort_authorized_at` is not null) and (`cohort_seed_count` is not null) and (`cohort_seed_fingerprint` is not null) and (`processing_started_at` is not null) and (`active_attempt_token_hash` is not null) and (`claimed_at` is not null) and (`attempt_lease_expires_at` is not null)))',
            'chk_import_claim_processing_time_order' => '((`processing_started_at` is null) or (`state` in (_utf8mb4\\\'terminal_qualified\\\',_utf8mb4\\\'terminal_frozen\\\',_utf8mb4\\\'terminal_failed\\\')) or ((`claimed_at` is not null) and (`processing_started_at` >= `claimed_at`) and (`processing_started_at` < `attempt_lease_expires_at`)))',
            'chk_import_claim_state' => '(`state` in (_ascii\\\'pending_dispatch\\\',_ascii\\\'queued\\\',_ascii\\\'processing\\\',_ascii\\\'terminal_qualified\\\',_ascii\\\'terminal_frozen\\\',_ascii\\\'terminal_failed\\\'))',
            'chk_import_claim_terminal_fields' => '(((`state` = _ascii\\\'terminal_qualified\\\') and (`terminal_at` is not null) and (`terminal_reason_code` is null)) or ((`state` in (_ascii\\\'terminal_frozen\\\',_ascii\\\'terminal_failed\\\')) and (`terminal_at` is not null) and (`terminal_reason_code` is not null)) or ((`state` not in (_ascii\\\'terminal_qualified\\\',_ascii\\\'terminal_frozen\\\',_ascii\\\'terminal_failed\\\')) and (`terminal_at` is null) and (`terminal_reason_code` is null)))',
            'chk_import_claim_terminal_owner_clear' => '((`state` not in (_ascii\\\'terminal_qualified\\\',_ascii\\\'terminal_frozen\\\',_ascii\\\'terminal_failed\\\')) or ((`active_attempt_token_hash` is null) and (`claimed_at` is null) and (`attempt_lease_expires_at` is null)))',
            'chk_import_execution_claim_allocation_pair' => '(((`supplier_feed_id` is null) and (`import_job_id` is null) and (`allocated_at` is null)) or ((`supplier_feed_id` is not null) and (`import_job_id` is not null) and (`allocated_at` is not null)))',
            'chk_import_execution_claim_fingerprint_allocation' => '((`source_fingerprint` is null) or ((`supplier_feed_id` is not null) and (`import_job_id` is not null) and (`allocated_at` is not null)))',
            'chk_import_execution_claim_history_allocation' => '((`import_history_id` is null) or ((`supplier_feed_id` is not null) and (`import_job_id` is not null) and (`allocated_at` is not null)))',
            'chk_import_execution_claim_logical_key' => '((length(`logical_execution_key`) = 64) and regexp_like(`logical_execution_key`,_ascii\\\'^[0-9a-f]{64}$\\\',_utf8mb4\\\'c\\\'))',
            'chk_import_execution_claim_path_parent' => '(((cast(`execution_path` as char charset binary) = cast(_ascii\\\'orchestrated\\\' as char charset binary)) and (length(`execution_path`) = 12) and (`supplier_import_run_id` is not null)) or ((cast(`execution_path` as char charset binary) = cast(_ascii\\\'legacy_xml\\\' as char charset binary)) and (length(`execution_path`) = 10) and (`supplier_import_run_id` is null) and (`supplier_feed_id` is not null) and (`import_job_id` is not null)))',
            'chk_import_execution_claim_processing_allocation' => '((`state` <> _ascii\\\'processing\\\') or ((`supplier_feed_id` is not null) and (`import_job_id` is not null) and (`allocated_at` is not null) and (`import_history_id` is not null) and (`source_fingerprint` is not null) and (`processing_started_at` is not null)))',
            'chk_import_execution_claim_source_fingerprint' => '((`source_fingerprint` is null) or ((length(`source_fingerprint`) = 64) and regexp_like(`source_fingerprint`,_ascii\\\'^[0-9a-f]{64}$\\\',_utf8mb4\\\'c\\\')))',
            'chk_import_execution_claim_terminal_evidence_allocation' => '((`state` not in (_ascii\\\'terminal_qualified\\\',_ascii\\\'terminal_frozen\\\')) or ((`supplier_feed_id` is not null) and (`import_job_id` is not null) and (`allocated_at` is not null) and (`import_history_id` is not null)))',
        ],
        'supplier_offer_snapshot_enrollments' => [
            'chk_snapshot_enrollment_fingerprint' => '((length(`enrollment_fingerprint`) = 64) and regexp_like(`enrollment_fingerprint`,_ascii\\\'^[0-9a-f]{64}$\\\',_utf8mb4\\\'c\\\'))',
            'chk_snapshot_enrollment_source' => '(cast(`enrollment_source` as char charset binary) in (cast(_ascii\\\'capture_start_seed\\\' as char charset binary),cast(_ascii\\\'exact_source_observation\\\' as char charset binary),cast(_ascii\\\'capture_start_seed_and_exact_source_observation\\\' as char charset binary)))',
            'chk_snapshot_enrollment_source_identity' => '((length(`source_identity`) between 1 and 128) and regexp_like(`source_identity`,_ascii\\\'^snapshot-source-v1:[a-z0-9]+([._-][a-z0-9]+)*(:[a-z0-9]+([._-][a-z0-9]+)*)*$\\\',_utf8mb4\\\'c\\\'))',
            'chk_snapshot_enrollment_supplier_sku_hash' => '((length(`supplier_sku_hash`) = 64) and regexp_like(`supplier_sku_hash`,_ascii\\\'^[0-9a-f]{64}$\\\',_utf8mb4\\\'c\\\'))',
            'chk_snapshot_enrollment_timestamp' => 'regexp_like(`enrolled_at`,_ascii\\\'^[0-9]{4}-(0[1-9]|1[0-2])-([0-2][0-9]|3[01])T([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9][+]00:00$\\\',_utf8mb4\\\'c\\\')',
        ],
        'supplier_offer_snapshot_generations' => [
            'chk_snapshot_generation_boolean_domains' => '((`freshness_policy_approved` in (0,1)) and (`successful` in (0,1)) and (`full` in (0,1)) and (`schema_valid` in (0,1)) and (`truncated` in (0,1)) and (`fatal_integrity_blocker` in (0,1)) and (`supplier_identity_confirmed` in (0,1)) and (`comparable` in (0,1)))',
            'chk_snapshot_generation_capture_outcome' => '(cast(`capture_outcome` as char charset binary) in (cast(_ascii\\\'completed\\\' as char charset binary),cast(_ascii\\\'completed_with_errors\\\' as char charset binary),cast(_ascii\\\'failed\\\' as char charset binary),cast(_ascii\\\'incomplete\\\' as char charset binary),cast(_ascii\\\'overflow\\\' as char charset binary)))',
            'chk_snapshot_generation_cohort_fingerprint' => '((`cohort_fingerprint` is null) or ((length(`cohort_fingerprint`) = 64) and regexp_like(`cohort_fingerprint`,_ascii\\\'^[0-9a-f]{64}$\\\',_utf8mb4\\\'c\\\')))',
            'chk_snapshot_generation_count_reconciliation' => '((`total_observed_count` = (((`valid_observation_count` + `invalid_observation_count`) + `rejected_observation_count`) + `duplicate_observation_count`)) and (`enrolled_observation_count` >= `valid_observation_count`))',
            'chk_snapshot_generation_fingerprint' => '((length(`generation_fingerprint`) = 64) and regexp_like(`generation_fingerprint`,_ascii\\\'^[0-9a-f]{64}$\\\',_utf8mb4\\\'c\\\'))',
            'chk_snapshot_generation_freshness_tuple' => '(((`freshness_policy_key` is null) and (`freshness_max_age_hours` is null) and (`freshness_policy_approved` = 0)) or ((`freshness_policy_key` is not null) and (`freshness_max_age_hours` is not null) and (`freshness_policy_approved` = 1)))',
            'chk_snapshot_generation_json_shapes' => '((json_type(`policy_versions`) = _utf8mb4\\\'OBJECT\\\') and (json_type(`qualification_reason_codes`) = _utf8mb4\\\'ARRAY\\\'))',
            'chk_snapshot_generation_observation_fingerprint' => '((`observation_set_fingerprint` is null) or ((length(`observation_set_fingerprint`) = 64) and regexp_like(`observation_set_fingerprint`,_ascii\\\'^[0-9a-f]{64}$\\\',_utf8mb4\\\'c\\\')))',
            'chk_snapshot_generation_qualification_state' => '(cast(`qualification_state` as char charset binary) in (cast(_ascii\\\'qualified_baseline\\\' as char charset binary),cast(_ascii\\\'qualified_comparable\\\' as char charset binary),cast(_ascii\\\'frozen\\\' as char charset binary)))',
            'chk_snapshot_generation_qualification_tuple' => '(((`qualification_state` = _utf8mb4\\\'qualified_baseline\\\') and (`predecessor_snapshot_generation_id` is null) and (`comparable` = 0) and (`product_drop_percent` is null) and (json_length(`qualification_reason_codes`) = 0) and (`successful` = 1) and (`full` = 1) and (`schema_valid` = 1) and (`truncated` = 0) and (`fatal_integrity_blocker` = 0) and (`supplier_identity_confirmed` = 1) and (`valid_observation_count` >= `minimum_product_count`) and (`cohort_fingerprint` is not null) and (`observation_set_fingerprint` is not null)) or ((`qualification_state` = _utf8mb4\\\'qualified_comparable\\\') and (`predecessor_snapshot_generation_id` is not null) and (`comparable` = 1) and (`product_drop_percent` is not null) and (`product_drop_percent` <= `maximum_product_drop_percent`) and (json_length(`qualification_reason_codes`) = 0) and (`successful` = 1) and (`full` = 1) and (`schema_valid` = 1) and (`truncated` = 0) and (`fatal_integrity_blocker` = 0) and (`supplier_identity_confirmed` = 1) and (`valid_observation_count` >= `minimum_product_count`) and (`cohort_fingerprint` is not null) and (`observation_set_fingerprint` is not null)) or ((`qualification_state` = _utf8mb4\\\'frozen\\\') and (json_length(`qualification_reason_codes`) > 0)))',
            'chk_snapshot_generation_source_fingerprint' => '((length(`source_fingerprint`) = 64) and regexp_like(`source_fingerprint`,_ascii\\\'^[0-9a-f]{64}$\\\',_utf8mb4\\\'c\\\'))',
            'chk_snapshot_generation_source_identity' => '((length(`source_identity`) between 1 and 128) and regexp_like(`source_identity`,_ascii\\\'^snapshot-source-v1:[a-z0-9]+([._-][a-z0-9]+)*(:[a-z0-9]+([._-][a-z0-9]+)*)*$\\\',_utf8mb4\\\'c\\\'))',
            'chk_snapshot_generation_thresholds' => '((`minimum_product_count` > 0) and (`maximum_product_drop_percent` <= 100) and ((`product_drop_percent` is null) or (`product_drop_percent` between 0 and 100)))',
            'chk_snapshot_generation_timestamps' => '(regexp_like(`captured_at`,_ascii\\\'^[0-9]{4}-(0[1-9]|1[0-2])-([0-2][0-9]|3[01])T([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9][+]00:00$\\\',_utf8mb4\\\'c\\\') and regexp_like(`capture_started_at`,_ascii\\\'^[0-9]{4}-(0[1-9]|1[0-2])-([0-2][0-9]|3[01])T([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9][+]00:00$\\\',_utf8mb4\\\'c\\\') and regexp_like(`capture_completed_at`,_ascii\\\'^[0-9]{4}-(0[1-9]|1[0-2])-([0-2][0-9]|3[01])T([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9][+]00:00$\\\',_utf8mb4\\\'c\\\') and ((`authoritative_snapshot_at` is null) or regexp_like(`authoritative_snapshot_at`,_ascii\\\'^[0-9]{4}-(0[1-9]|1[0-2])-([0-2][0-9]|3[01])T([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9][+]00:00$\\\',_utf8mb4\\\'c\\\')) and (`captured_at` = `capture_completed_at`) and (`capture_started_at` <= `capture_completed_at`) and ((`authoritative_snapshot_at` is null) or (`authoritative_snapshot_at` <= `captured_at`)))',
        ],
        'supplier_offer_snapshot_observations' => [
            'chk_snapshot_observation_absent_semantics' => '((`present` = 1) or ((`price` is null) and (`currency` is null) and (`raw_quantity_observed` is null) and (`eol_flag` is null) and (`canonical_public_status` is null) and (`supplier_mapper_valid` = 0) and (`exact_supplier_sku_match` = 0) and (`identifier_conflict` = 0) and (`blocking_validation_issue` = 0) and (`duplicate_offer` = 0) and (`reliable_manufacturer_mpn_hash` is null)))',
            'chk_snapshot_observation_boolean_domains' => '((`present` in (0,1)) and (`supplier_mapper_valid` in (0,1)) and (`exact_supplier_sku_match` in (0,1)) and (`identifier_conflict` in (0,1)) and (`blocking_validation_issue` in (0,1)) and (`duplicate_offer` in (0,1)) and ((`eol_flag` is null) or (`eol_flag` in (0,1))))',
            'chk_snapshot_observation_currency' => '((`currency` is null) or ((length(`currency`) = 3) and regexp_like(`currency`,_ascii\\\'^[A-Z]{3}$\\\',_utf8mb4\\\'c\\\')))',
            'chk_snapshot_observation_fingerprint' => '((length(`observation_fingerprint`) = 64) and regexp_like(`observation_fingerprint`,_ascii\\\'^[0-9a-f]{64}$\\\',_utf8mb4\\\'c\\\'))',
            'chk_snapshot_observation_mpn_hash' => '((`reliable_manufacturer_mpn_hash` is null) or ((length(`reliable_manufacturer_mpn_hash`) = 64) and regexp_like(`reliable_manufacturer_mpn_hash`,_ascii\\\'^[0-9a-f]{64}$\\\',_utf8mb4\\\'c\\\')))',
            'chk_snapshot_observation_price' => '((`price` is null) or (`price` >= 0))',
            'chk_snapshot_observation_supplier_sku_hash' => '((length(`supplier_sku_hash`) = 64) and regexp_like(`supplier_sku_hash`,_ascii\\\'^[0-9a-f]{64}$\\\',_utf8mb4\\\'c\\\'))',
        ],
    ];

    /** @var list<array{trigger_name: string, table_name: string, timing: string, event: string, statement: string}> */
    private const EXPECTED_TRIGGERS = [
        [
            'trigger_name' => 'trg_import_cohort_auth_no_delete',
            'table_name' => 'supplier_import_cohort_authorization_members',
            'timing' => 'BEFORE',
            'event' => 'DELETE',
            'statement' => "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable supplier snapshot row cannot be deleted'",
        ],
        [
            'trigger_name' => 'trg_import_cohort_auth_no_update',
            'table_name' => 'supplier_import_cohort_authorization_members',
            'timing' => 'BEFORE',
            'event' => 'UPDATE',
            'statement' => "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable supplier snapshot row cannot be updated'",
        ],
        [
            'trigger_name' => 'trg_import_execution_claim_path_immutable',
            'table_name' => 'supplier_import_execution_claims',
            'timing' => 'BEFORE',
            'event' => 'UPDATE',
            'statement' => "BEGIN IF BINARY OLD.execution_path <> BINARY NEW.execution_path THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Execution claim path is immutable'; END IF; END",
        ],
        [
            'trigger_name' => 'trg_import_recovery_auth_no_delete',
            'table_name' => 'supplier_import_dispatch_recovery_authorizations',
            'timing' => 'BEFORE',
            'event' => 'DELETE',
            'statement' => "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable supplier snapshot row cannot be deleted'",
        ],
        [
            'trigger_name' => 'trg_import_recovery_auth_no_update',
            'table_name' => 'supplier_import_dispatch_recovery_authorizations',
            'timing' => 'BEFORE',
            'event' => 'UPDATE',
            'statement' => "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable supplier snapshot row cannot be updated'",
        ],
        [
            'trigger_name' => 'trg_import_recovery_result_no_delete',
            'table_name' => 'supplier_import_dispatch_recovery_results',
            'timing' => 'BEFORE',
            'event' => 'DELETE',
            'statement' => "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable supplier snapshot row cannot be deleted'",
        ],
        [
            'trigger_name' => 'trg_import_recovery_result_no_update',
            'table_name' => 'supplier_import_dispatch_recovery_results',
            'timing' => 'BEFORE',
            'event' => 'UPDATE',
            'statement' => "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable supplier snapshot row cannot be updated'",
        ],
        [
            'trigger_name' => 'trg_snapshot_enrollment_no_delete',
            'table_name' => 'supplier_offer_snapshot_enrollments',
            'timing' => 'BEFORE',
            'event' => 'DELETE',
            'statement' => "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable supplier snapshot row cannot be deleted'",
        ],
        [
            'trigger_name' => 'trg_snapshot_enrollment_no_update',
            'table_name' => 'supplier_offer_snapshot_enrollments',
            'timing' => 'BEFORE',
            'event' => 'UPDATE',
            'statement' => "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable supplier snapshot row cannot be updated'",
        ],
        [
            'trigger_name' => 'trg_snapshot_generation_no_delete',
            'table_name' => 'supplier_offer_snapshot_generations',
            'timing' => 'BEFORE',
            'event' => 'DELETE',
            'statement' => "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable supplier snapshot row cannot be deleted'",
        ],
        [
            'trigger_name' => 'trg_snapshot_generation_no_update',
            'table_name' => 'supplier_offer_snapshot_generations',
            'timing' => 'BEFORE',
            'event' => 'UPDATE',
            'statement' => "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable supplier snapshot row cannot be updated'",
        ],
        [
            'trigger_name' => 'trg_snapshot_observation_no_delete',
            'table_name' => 'supplier_offer_snapshot_observations',
            'timing' => 'BEFORE',
            'event' => 'DELETE',
            'statement' => "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable supplier snapshot row cannot be deleted'",
        ],
        [
            'trigger_name' => 'trg_snapshot_observation_no_update',
            'table_name' => 'supplier_offer_snapshot_observations',
            'timing' => 'BEFORE',
            'event' => 'UPDATE',
            'statement' => "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable supplier snapshot row cannot be updated'",
        ],
    ];

    /** @var list<array<string, mixed>> */
    private const EXPECTED_GENERATED_GUARDS = [
        [
            'table_name' => 'supplier_import_dispatch_recovery_results',
            'column_name' => 'started_once_guard',
            'column_type' => 'tinyint unsigned',
            'is_nullable' => 'YES',
            'extra' => 'STORED GENERATED',
            'expression' => "(case when (cast(`event_kind` as char charset binary) = cast(_ascii\\'started\\' as char charset binary)) then 1 else NULL end)",
            'index_name' => 'uq_import_recovery_result_auth_started',
            'non_unique' => 0,
            'index_columns' => [
                'supplier_import_dispatch_recovery_authorization_id',
                'started_once_guard',
            ],
        ],
        [
            'table_name' => 'supplier_import_dispatch_recovery_results',
            'column_name' => 'terminal_once_guard',
            'column_type' => 'tinyint unsigned',
            'is_nullable' => 'YES',
            'extra' => 'STORED GENERATED',
            'expression' => "(case when (cast(`event_kind` as char charset binary) in (cast(_ascii\\'republish_succeeded\\' as char charset binary),cast(_ascii\\'terminalization_succeeded\\' as char charset binary),cast(_ascii\\'ownership_recovery_succeeded\\' as char charset binary),cast(_ascii\\'publish_failed\\' as char charset binary),cast(_ascii\\'action_stopped\\' as char charset binary),cast(_ascii\\'rejected\\' as char charset binary),cast(_ascii\\'already_terminal\\' as char charset binary))) then 1 else NULL end)",
            'index_name' => 'uq_import_recovery_result_auth_terminal',
            'non_unique' => 0,
            'index_columns' => [
                'supplier_import_dispatch_recovery_authorization_id',
                'terminal_once_guard',
            ],
        ],
    ];

    private const DOWN_CAPABILITY_ENV = 'SUPPLIER_SNAPSHOT_EMPTY_SCHEMA_DOWN_CAPABILITY';

    private const WINDOWS_WRITE_RIGHTS_MASK = 852310;

    private const FIRST_DOWN_MIGRATION = '2026_08_20_120011_add_supplier_range_index_to_import_histories';

    /** @var list<string> */
    private const PHASE_I_MIGRATIONS = [
        '2026_08_20_120000_add_supplier_ownership_key_to_import_jobs',
        '2026_08_20_120001_create_supplier_import_execution_claims_table',
        '2026_08_20_120002_create_supplier_import_dispatch_outbox_table',
        '2026_08_20_120003_create_supplier_import_dispatch_monitor_health_table',
        '2026_08_20_120004_create_supplier_import_dispatch_alert_intents_table',
        '2026_08_20_120005_create_supplier_import_dispatch_recovery_authorizations_table',
        '2026_08_20_120006_create_supplier_import_dispatch_recovery_results_table',
        '2026_08_20_120007_create_supplier_import_cohort_authorization_members_table',
        '2026_08_20_120008_create_supplier_offer_snapshot_generations_table',
        '2026_08_20_120009_create_supplier_offer_snapshot_enrollments_table',
        '2026_08_20_120010_create_supplier_offer_snapshot_observations_table',
        self::FIRST_DOWN_MIGRATION,
    ];

    /** @var list<string> */
    private const CANONICAL_TABLES = [
        'supplier_import_execution_claims',
        'supplier_import_dispatch_outbox',
        'supplier_import_dispatch_monitor_health',
        'supplier_import_dispatch_alert_intents',
        'supplier_import_dispatch_recovery_authorizations',
        'supplier_import_dispatch_recovery_results',
        'supplier_import_cohort_authorization_members',
        'supplier_offer_snapshot_generations',
        'supplier_offer_snapshot_enrollments',
        'supplier_offer_snapshot_observations',
    ];

    /** @var array<string, string> */
    private const SECURITY_COLUMNS = [
        'supplier_import_cohort_authorization_members.supplier_sku_hash' => 'NO',
        'supplier_import_dispatch_alert_intents.alert_identity' => 'NO',
        'supplier_import_dispatch_alert_intents.delivery_owner_token_hash' => 'YES',
        'supplier_import_dispatch_monitor_health.monitor_owner_token_hash' => 'YES',
        'supplier_import_dispatch_outbox.dispatch_payload_hash' => 'NO',
        'supplier_import_dispatch_outbox.lease_token_hash' => 'YES',
        'supplier_import_dispatch_outbox.logical_execution_key' => 'NO',
        'supplier_import_dispatch_outbox.publication_attempt_token_hash' => 'YES',
        'supplier_import_dispatch_recovery_authorizations.authorization_nonce_hash' => 'NO',
        'supplier_import_dispatch_recovery_authorizations.expected_state_fingerprint' => 'NO',
        'supplier_import_dispatch_recovery_authorizations.logical_execution_key' => 'NO',
        'supplier_import_dispatch_recovery_results.logical_execution_key' => 'NO',
        'supplier_import_dispatch_recovery_results.result_fingerprint' => 'NO',
        'supplier_import_dispatch_recovery_results.resume_state_fingerprint' => 'YES',
        'supplier_import_execution_claims.active_attempt_token_hash' => 'YES',
        'supplier_import_execution_claims.cohort_seed_fingerprint' => 'YES',
        'supplier_import_execution_claims.logical_execution_key' => 'NO',
        'supplier_import_execution_claims.source_fingerprint' => 'YES',
        'supplier_offer_snapshot_enrollments.enrollment_fingerprint' => 'NO',
        'supplier_offer_snapshot_enrollments.supplier_sku_hash' => 'NO',
        'supplier_offer_snapshot_generations.cohort_fingerprint' => 'YES',
        'supplier_offer_snapshot_generations.generation_fingerprint' => 'NO',
        'supplier_offer_snapshot_generations.observation_set_fingerprint' => 'YES',
        'supplier_offer_snapshot_generations.source_fingerprint' => 'NO',
        'supplier_offer_snapshot_observations.observation_fingerprint' => 'NO',
        'supplier_offer_snapshot_observations.reliable_manufacturer_mpn_hash' => 'YES',
        'supplier_offer_snapshot_observations.supplier_sku_hash' => 'NO',
    ];

    /** @var list<string> */
    private array $issuedDownCapabilities = [];

    protected function setUp(): void
    {
        parent::setUp();

        app(ConsoleKernel::class)->rerouteSymfonyCommandEvents();
        CanonicalSupplierSnapshotSchema::bootstrapDestructiveDownGuard();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Canonical supplier snapshot schema requires MySQL 8.4.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->issuedDownCapabilities as $capability) {
            CanonicalSupplierSnapshotSchema::revokeDestructiveDownCapability($capability);
            $this->purgeCapabilityLedgerForTesting($capability);
        }
        $this->removeEmptyCapabilityRootsForTesting();
        $this->clearDownCapabilityEnvironment();
        $this->resetDownGuard();

        if (DB::getDriverName() === 'mysql') {
            DB::setDefaultConnection((string) config('database.default'));
            $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        }

        parent::tearDown();
    }

    public function test_mysql_84_schema_matches_the_canonical_inventory(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        $version = (string) DB::scalar('SELECT VERSION()');
        $this->assertStringStartsWith('8.4.', $version);

        foreach (self::CANONICAL_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing canonical table {$table}.");
            $create = (array) DB::selectOne(sprintf('SHOW CREATE TABLE `%s`', $table));
            $this->assertStringContainsString('ENGINE=InnoDB', (string) array_values($create)[1]);
        }

        $this->assertSame(10, collect(DB::select(<<<'SQL'
            SELECT TABLE_NAME
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN (
                    'supplier_import_execution_claims',
                    'supplier_import_dispatch_outbox',
                    'supplier_import_dispatch_monitor_health',
                    'supplier_import_dispatch_alert_intents',
                    'supplier_import_dispatch_recovery_authorizations',
                    'supplier_import_dispatch_recovery_results',
                    'supplier_import_cohort_authorization_members',
                    'supplier_offer_snapshot_generations',
                    'supplier_offer_snapshot_enrollments',
                    'supplier_offer_snapshot_observations'
                )
            SQL))->count());

        $this->assertIndexInventory();
        $this->assertForeignKeyInventory();
        $this->assertCheckInventory();
        $this->assertTriggerInventory();
        $this->assertGeneratedGuardInventory();
        $this->assertHexColumnInventory();
        $this->assertPristineMonitor();
    }

    public function test_mysql_constraints_reject_cross_parent_and_immutable_mutations(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        $fixture = $this->seedProtectedGraph();

        $this->assertQueryRejected(function () use ($fixture): void {
            DB::table('supplier_import_execution_claims')->insert([
                'logical_execution_key' => str_repeat('d', 64),
                'supplier_id' => $fixture['supplier_id'],
                'supplier_import_run_id' => $fixture['run_id'],
                'execution_path' => 'ORCHESTRATED',
            ]);
        }, 'check constraint');

        $validOrchestratedClaimId = DB::table('supplier_import_execution_claims')->insertGetId([
            'logical_execution_key' => str_repeat('e', 64),
            'supplier_id' => $fixture['supplier_id'],
            'supplier_import_run_id' => $fixture['run_id'],
            'execution_path' => 'orchestrated',
        ]);

        $this->assertSame(1, DB::table('supplier_import_execution_claims')
            ->where('id', $validOrchestratedClaimId)
            ->update(['state' => 'queued']));
        $this->assertQueryRejected(function () use ($validOrchestratedClaimId): void {
            DB::table('supplier_import_execution_claims')
                ->where('id', $validOrchestratedClaimId)
                ->update(['execution_path' => 'legacy_xml']);
        }, 'immutable');

        $other = $this->seedParentFixture('other');
        $this->assertQueryRejected(function () use ($fixture, $other): void {
            DB::table('supplier_import_execution_claims')->insert([
                'logical_execution_key' => str_repeat('f', 64),
                'supplier_id' => $fixture['supplier_id'],
                'supplier_feed_id' => $other['feed_id'],
                'import_job_id' => $other['job_id'],
                'allocated_at' => '2026-08-20 08:00:00.000000',
                'execution_path' => 'legacy_xml',
            ]);
        }, 'foreign key constraint');

        $this->assertQueryRejected(function () use ($fixture): void {
            DB::table('suppliers')->where('id', $fixture['supplier_id'])->delete();
        }, 'foreign key constraint');
        $this->assertDatabaseHas('suppliers', ['id' => $fixture['supplier_id']]);

        foreach ([
            ['supplier_import_dispatch_recovery_authorizations', $fixture['authorization_id']],
            ['supplier_import_dispatch_recovery_results', $fixture['result_id']],
            ['supplier_import_cohort_authorization_members', $fixture['cohort_member_id']],
            ['supplier_offer_snapshot_generations', $fixture['generation_id']],
            ['supplier_offer_snapshot_enrollments', $fixture['enrollment_id']],
            ['supplier_offer_snapshot_observations', $fixture['observation_id']],
        ] as [$table, $id]) {
            $this->assertQueryRejected(function () use ($table, $id): void {
                DB::table($table)->where('id', $id)->update(['id' => $id]);
            }, 'immutable');
            $this->assertQueryRejected(function () use ($table, $id): void {
                DB::table($table)->where('id', $id)->delete();
            }, 'immutable');
            $this->assertDatabaseHas($table, ['id' => $id]);
        }

        $this->assertQueryRejected(function () use ($fixture): void {
            DB::table('supplier_import_dispatch_recovery_results')->insert([
                'supplier_import_dispatch_recovery_authorization_id' => $fixture['authorization_id'],
                'authorization_action' => 'republish_same_key',
                'authorized_operator_id' => $fixture['user_id'],
                'supplier_import_execution_claim_id' => $fixture['claim_id'],
                'supplier_import_dispatch_outbox_id' => $fixture['outbox_id'],
                'logical_execution_key' => str_repeat('a', 64),
                'target_parent_type' => 'supplier_feed',
                'target_parent_id' => $fixture['feed_id'],
                'event_sequence' => 1,
                'event_kind' => 'started',
                'canonical_result_code' => 'authorization_attempt_started',
                'resume_state_fingerprint' => str_repeat('9', 64),
                'occurred_at' => '2026-08-20 08:02:00.000000',
                'result_fingerprint' => str_repeat('8', 64),
            ]);
        }, 'duplicate');

    }

    public function test_mysql_restrictive_fk_races_use_distinct_transactions_and_exact_errors(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        config([
            'database.connections.snapshot_schema_race_a' => config('database.connections.mysql'),
            'database.connections.snapshot_schema_race_b' => config('database.connections.mysql'),
        ]);
        DB::purge('snapshot_schema_race_a');
        DB::purge('snapshot_schema_race_b');

        $connectionA = DB::connection('snapshot_schema_race_a');
        $connectionB = DB::connection('snapshot_schema_race_b');
        $pdoA = $connectionA->getPdo();
        $pdoB = $connectionB->getPdo();
        $timeoutA = (int) $connectionA->scalar('SELECT @@SESSION.innodb_lock_wait_timeout');
        $timeoutB = (int) $connectionB->scalar('SELECT @@SESSION.innodb_lock_wait_timeout');

        try {
            $this->assertNotSame(
                (int) $connectionA->scalar('SELECT CONNECTION_ID()'),
                (int) $connectionB->scalar('SELECT CONNECTION_ID()'),
            );
            $connectionA->statement('SET SESSION innodb_lock_wait_timeout = 1');
            $connectionB->statement('SET SESSION innodb_lock_wait_timeout = 1');

            $this->assertParentDeleteWinsRace($pdoA, $pdoB);
            $this->assertChildInsertWinsRace($pdoA, $pdoB);
        } finally {
            $this->rollbackPdo($pdoA);
            $this->rollbackPdo($pdoB);
            $connectionA->statement('SET SESSION innodb_lock_wait_timeout = '.$timeoutA);
            $connectionB->statement('SET SESSION innodb_lock_wait_timeout = '.$timeoutB);
            DB::disconnect('snapshot_schema_race_a');
            DB::disconnect('snapshot_schema_race_b');
            DB::purge('snapshot_schema_race_a');
            DB::purge('snapshot_schema_race_b');
        }
    }

    public function test_guarded_down_fails_closed_and_empty_schema_round_trips(): void
    {
        $database = 'phase_i_schema_'.getmypid().'_'.strtolower(bin2hex(random_bytes(4)));
        $historicalPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'_migrations';
        $originalEnvironment = app()->environment();
        $originalConfirmation = getenv('SUPPLIER_SNAPSHOT_EMPTY_SCHEMA_DOWN_CONFIRMED');
        $originalCapability = getenv(self::DOWN_CAPABILITY_ENV);
        $originalDefaultConnection = DB::getDefaultConnection();

        try {
            $this->createTemporaryDatabase($database);
            $this->configureTemporaryConnection($database);
            $this->copyHistoricalMigrations($historicalPath);
            $this->migrateHistoricalThenPhase($historicalPath);
            DB::setDefaultConnection('snapshot_schema_phase_i');

            $this->clearDownCapabilityEnvironment();
            $this->assertGuardRejectedContaining('one-use invocation capability is missing or malformed');

            $productionCapability = $this->issueDownCapability();
            app()->detectEnvironment(static fn (): string => 'production');
            $this->assertGuardRejectedContaining('environment must be exactly local or testing');
            $this->assertCapabilitySpentAndArtifactAbsent($productionCapability, 'wrong environment');
            app()->detectEnvironment(static fn () => $originalEnvironment);

            $gateCapability = $this->issueDownCapability();
            config(['supplier_snapshot_capture.monitor_schedule_enabled' => true]);
            $this->assertGuardRejectedContaining('forward gate supplier_snapshot_capture.monitor_schedule_enabled is not disabled');
            $this->assertCapabilitySpentAndArtifactAbsent($gateCapability, 'enabled forward gate');
            config(['supplier_snapshot_capture.monitor_schedule_enabled' => false]);

            CanonicalSupplierSnapshotSchema::dropTriggers([
                'trg_snapshot_observation_no_update',
                'trg_snapshot_observation_no_delete',
            ]);
            Schema::drop('supplier_offer_snapshot_observations');
            $partialSchemaCapability = $this->issueDownCapability();
            $this->assertGuardRejectedContaining('expected table supplier_offer_snapshot_observations is missing');
            $this->assertCapabilitySpentAndArtifactAbsent($partialSchemaCapability, 'partial schema');
            foreach (array_diff(self::CANONICAL_TABLES, ['supplier_offer_snapshot_observations']) as $table) {
                $this->assertTrue(Schema::hasTable($table));
            }

            DB::setDefaultConnection($originalDefaultConnection);
            $this->recreateTemporaryDatabase($database);
            $this->migrateHistoricalThenPhase($historicalPath);
            DB::setDefaultConnection('snapshot_schema_phase_i');
            $this->seedProtectedGraph('snapshot_schema_phase_i');
            $evidenceCapability = $this->issueDownCapability();
            $message = $this->guardRejectionMessage();
            $this->assertCapabilitySpentAndArtifactAbsent($evidenceCapability, 'protected evidence present');
            foreach (array_diff(self::CANONICAL_TABLES, ['supplier_import_dispatch_monitor_health']) as $table) {
                $this->assertStringContainsString($table, $message);
                $this->assertTrue(Schema::hasTable($table));
                $this->assertGreaterThan(0, DB::table($table)->count());
            }

            DB::setDefaultConnection($originalDefaultConnection);
            $this->recreateTemporaryDatabase($database);
            $this->migrateHistoricalThenPhase($historicalPath);
            DB::setDefaultConnection('snapshot_schema_phase_i');
            DB::table('supplier_import_dispatch_monitor_health')->where('id', 1)->update([
                'integrity_state' => 'stale',
            ]);
            $monitorCapability = $this->issueDownCapability();
            $this->assertGuardRejectedContaining('monitor singleton column integrity_state is not pristine');
            $this->assertCapabilitySpentAndArtifactAbsent($monitorCapability, 'malformed monitor singleton');
            $this->assertSame(10, collect(self::CANONICAL_TABLES)->filter(
                static fn (string $table): bool => Schema::hasTable($table),
            )->count());

            DB::setDefaultConnection($originalDefaultConnection);
            $this->recreateTemporaryDatabase($database);
            $this->migrateHistoricalThenPhase($historicalPath);
            DB::setDefaultConnection('snapshot_schema_phase_i');
            putenv('SUPPLIER_SNAPSHOT_EMPTY_SCHEMA_DOWN_CONFIRMED=true');
            config(['supplier_snapshot_capture.destructive_down_confirmed' => true]);
            CanonicalSupplierSnapshotSchema::revokeDestructiveDownCapability(str_repeat('a', 64));
            putenv(self::DOWN_CAPABILITY_ENV.'='.str_repeat('a', 64));
            $this->assertGuardRejectedContaining('authoritative issued ledger record is missing');
            $this->assertUnissuedCapabilityStateAbsent(str_repeat('a', 64), 'raw token without authoritative issuance');
            $this->assertSame(10, collect(self::CANONICAL_TABLES)->filter(
                static fn (string $table): bool => Schema::hasTable($table),
            )->count());
            config(['supplier_snapshot_capture.destructive_down_confirmed' => null]);

            DB::setDefaultConnection($originalDefaultConnection);
            $this->recreateTemporaryDatabase($database);
            $this->migrateHistoricalThenPhase($historicalPath);
            DB::setDefaultConnection('snapshot_schema_phase_i');
            $before = $this->canonicalCreateStatements();
            $consumedCapability = $this->issueDownCapability();
            DB::setDefaultConnection($originalDefaultConnection);

            $this->assertSame(0, Artisan::call('migrate:rollback', [
                '--database' => 'snapshot_schema_phase_i',
                '--force' => true,
            ]), Artisan::output());
            $this->assertCapabilitySpentAndArtifactAbsent($consumedCapability, 'successful full rollback');

            DB::setDefaultConnection('snapshot_schema_phase_i');
            foreach (self::CANONICAL_TABLES as $table) {
                $this->assertFalse(Schema::hasTable($table));
            }
            $this->assertTrue(Schema::hasTable('suppliers'));
            $this->assertTrue(Schema::hasTable('import_jobs'));
            $this->assertFalse($this->indexExists('import_jobs', 'uq_import_job_id_supplier_feed'));
            $this->assertFalse($this->indexExists('import_histories', 'ix_import_history_supplier_id'));

            DB::setDefaultConnection($originalDefaultConnection);
            $this->assertSame(0, Artisan::call('migrate', [
                '--database' => 'snapshot_schema_phase_i',
                '--path' => database_path('migrations'),
                '--realpath' => true,
                '--force' => true,
            ]), Artisan::output());
            DB::setDefaultConnection('snapshot_schema_phase_i');
            $this->assertSame($before, $this->canonicalCreateStatements());
            $this->assertPristineMonitor();

            DB::table('supplier_import_dispatch_monitor_health')->where('id', 1)->update([
                'integrity_state' => 'stale',
            ]);
            config(['supplier_snapshot_capture.monitor_schedule_enabled' => true]);
            putenv('SUPPLIER_SNAPSHOT_EMPTY_SCHEMA_DOWN_CONFIRMED=true');
            $this->clearDownCapabilityEnvironment();
            $this->assertGuardRejectedContaining('one-use invocation capability is missing or malformed');
            $this->assertFalse(getenv(self::DOWN_CAPABILITY_ENV));
            $this->assertTrue((bool) config('supplier_snapshot_capture.monitor_schedule_enabled'));
            $this->assertSame('stale', DB::table('supplier_import_dispatch_monitor_health')->value('integrity_state'));
            $this->assertTrue($this->indexExists('import_jobs', 'uq_import_job_id_supplier_feed'));
            $this->assertTrue($this->indexExists('import_histories', 'ix_import_history_supplier_id'));
            $this->assertSame(10, collect(self::CANONICAL_TABLES)->filter(
                static fn (string $table): bool => Schema::hasTable($table),
            )->count());
        } finally {
            app()->detectEnvironment(static fn () => $originalEnvironment);
            config(['supplier_snapshot_capture.monitor_schedule_enabled' => false]);
            config(['supplier_snapshot_capture.destructive_down_confirmed' => null]);
            $this->resetDownGuard();
            if ($originalConfirmation === false) {
                putenv('SUPPLIER_SNAPSHOT_EMPTY_SCHEMA_DOWN_CONFIRMED');
            } else {
                putenv('SUPPLIER_SNAPSHOT_EMPTY_SCHEMA_DOWN_CONFIRMED='.$originalConfirmation);
            }
            if ($originalCapability === false) {
                $this->clearDownCapabilityEnvironment();
            } else {
                putenv(self::DOWN_CAPABILITY_ENV.'='.$originalCapability);
            }
            DB::setDefaultConnection($originalDefaultConnection);
            DB::disconnect('snapshot_schema_phase_i');
            DB::purge('snapshot_schema_phase_i');
            $this->dropTemporaryDatabase($database);
            File::deleteDirectory($historicalPath);
        }
    }

    public function test_failed_scope_and_out_of_sequence_steps_cannot_reuse_authorization(): void
    {
        $database = 'phase_i_scope_'.getmypid().'_'.strtolower(bin2hex(random_bytes(4)));
        $historicalPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'_migrations';
        $originalDefaultConnection = DB::getDefaultConnection();
        $blocker = null;
        $originalLockTimeout = null;

        try {
            $this->createTemporaryDatabase($database);
            $this->configureTemporaryConnection($database);
            $this->copyHistoricalMigrations($historicalPath);
            $this->migrateHistoricalThenPhase($historicalPath);
            DB::setDefaultConnection('snapshot_schema_phase_i');

            config(['database.connections.snapshot_schema_scope_blocker' => array_merge(
                config('database.connections.mysql'),
                ['database' => $database],
            )]);
            DB::purge('snapshot_schema_scope_blocker');
            $blocker = DB::connection('snapshot_schema_scope_blocker');
            $originalLockTimeout = (int) DB::scalar('SELECT @@SESSION.lock_wait_timeout');
            DB::statement('SET SESSION lock_wait_timeout = 1');
            $blocker->beginTransaction();
            $blocker->select('SELECT id FROM import_histories LIMIT 1 FOR UPDATE');
            $failedCommandCapability = $this->issueDownCapability();

            $this->assertStringContainsStringIgnoringCase(
                'lock wait timeout',
                $this->guardRejectionMessage(),
            );
            $this->assertCapabilitySpentAndArtifactAbsent($failedCommandCapability, 'rollback command failure');
            $blocker->rollBack();
            $this->clearDownCapabilityEnvironment();
            $this->assertTrue($this->indexExists('import_histories', 'ix_import_history_supplier_id'));
            $this->assertGuardRejectedContaining('one-use invocation capability is missing or malformed');
            $this->assertSame(10, collect(self::CANONICAL_TABLES)->filter(
                static fn (string $table): bool => Schema::hasTable($table),
            )->count());
            DB::statement('SET SESSION lock_wait_timeout = '.$originalLockTimeout);
            DB::disconnect('snapshot_schema_scope_blocker');
            DB::purge('snapshot_schema_scope_blocker');

            DB::setDefaultConnection($originalDefaultConnection);
            $this->recreateTemporaryDatabase($database);
            $this->migrateHistoricalThenPhase($historicalPath);
            DB::setDefaultConnection('snapshot_schema_phase_i');
            $migration = DB::table('migrations')->where('migration', self::FIRST_DOWN_MIGRATION)->first();
            $this->assertNotNull($migration);
            DB::table('migrations')->where('migration', self::FIRST_DOWN_MIGRATION)->delete();
            $missingMigrationCapability = $this->issueDownCapability();

            $this->assertGuardRejectedContaining('latest migration batch must be exactly the 12 canonical Phase I migrations');
            $this->assertCapabilitySpentAndArtifactAbsent($missingMigrationCapability, 'missing canonical migration');
            $this->assertTrue(Schema::hasTable('supplier_offer_snapshot_observations'));
            $this->assertTrue($this->indexExists('import_histories', 'ix_import_history_supplier_id'));
            DB::table('migrations')->insert((array) $migration);
        } finally {
            if ($blocker !== null && $blocker->transactionLevel() > 0) {
                $blocker->rollBack();
            }
            if ($originalLockTimeout !== null) {
                DB::setDefaultConnection('snapshot_schema_phase_i');
                DB::statement('SET SESSION lock_wait_timeout = '.$originalLockTimeout);
            }
            DB::disconnect('snapshot_schema_scope_blocker');
            DB::purge('snapshot_schema_scope_blocker');
            $this->resetDownGuard();
            $this->clearDownCapabilityEnvironment();
            DB::setDefaultConnection($originalDefaultConnection);
            DB::disconnect('snapshot_schema_phase_i');
            DB::purge('snapshot_schema_phase_i');
            $this->dropTemporaryDatabase($database);
            File::deleteDirectory($historicalPath);
        }
    }

    public function test_partial_filtered_mixed_and_wrong_commands_fail_before_first_ddl(): void
    {
        $database = 'phase_i_command_gate_'.getmypid().'_'.strtolower(bin2hex(random_bytes(4)));
        $historicalPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'_migrations';
        $originalDefaultConnection = DB::getDefaultConnection();

        try {
            $this->createTemporaryDatabase($database);
            $this->configureTemporaryConnection($database);
            $this->copyHistoricalMigrations($historicalPath);
            $this->migrateHistoricalThenPhase($historicalPath);
            DB::setDefaultConnection('snapshot_schema_phase_i');
            $batch = (int) DB::table('migrations')->max('batch');

            $rollbackSelectors = [
                '--step=1' => ['--step' => 1],
                '--step=12' => ['--step' => 12],
                '--batch' => ['--batch' => $batch],
                '--path' => ['--path' => [database_path('migrations')]],
                '--realpath' => ['--realpath' => true],
                '--pretend' => ['--pretend' => true],
            ];

            foreach ($rollbackSelectors as $label => $selector) {
                $selectorCapability = $this->issueDownCapability();
                $message = $this->commandRejectionMessage('migrate:rollback', $selector);
                $this->assertStringContainsString('rollback selector', $message, $label);
                $this->assertCapabilitySpentAndArtifactAbsent($selectorCapability, $label);
                $this->assertPhaseISchemaUntouched(12, $label);
                $replay = $this->rollbackAttempt($selectorCapability);
                $this->assertNotSame(0, $replay['exit'], $label.' replay');
                $this->assertStringContainsString('already spent or revoked', $replay['message'], $label.' replay');
                $this->assertPhaseISchemaUntouched(12, $label.' replay');
            }

            DB::table('migrations')->insert([
                'migration' => '2026_08_20_120012_unrelated_synthetic_migration',
                'batch' => $batch,
            ]);
            $extraMigrationCapability = $this->issueDownCapability();
            $this->assertStringContainsString(
                'latest migration batch must be exactly the 12 canonical Phase I migrations',
                $this->commandRejectionMessage('migrate:rollback'),
            );
            $this->assertCapabilitySpentAndArtifactAbsent($extraMigrationCapability, 'extra latest-batch migration');
            $this->assertPhaseISchemaUntouched(12, 'extra latest-batch migration');
            $this->assertSame(1, DB::table('migrations')->where(
                'migration',
                '2026_08_20_120012_unrelated_synthetic_migration',
            )->count());
            DB::table('migrations')->where(
                'migration',
                '2026_08_20_120012_unrelated_synthetic_migration',
            )->delete();

            $missing = DB::table('migrations')->where('migration', self::FIRST_DOWN_MIGRATION)->first();
            $this->assertNotNull($missing);
            DB::table('migrations')->where('migration', self::FIRST_DOWN_MIGRATION)->delete();
            $missingMigrationCapability = $this->issueDownCapability();
            $this->assertStringContainsString(
                'latest migration batch must be exactly the 12 canonical Phase I migrations',
                $this->commandRejectionMessage('migrate:rollback'),
            );
            $this->assertCapabilitySpentAndArtifactAbsent($missingMigrationCapability, 'missing canonical migration row');
            $this->assertPhaseISchemaUntouched(11, 'missing canonical migration row');
            DB::table('migrations')->insert((array) $missing);
            $this->assertPhaseISchemaUntouched(12, 'restored canonical migration row');

            foreach (['migrate:reset', 'migrate:refresh'] as $command) {
                $wrongCommandCapability = $this->issueDownCapability();
                $this->assertStringContainsString(
                    sprintf('command %s is not allowed', $command),
                    $this->commandRejectionMessage($command),
                );
                $this->assertCapabilitySpentAndArtifactAbsent($wrongCommandCapability, $command);
                $this->assertPhaseISchemaUntouched(12, $command);
            }

            $migration = require database_path(
                'migrations/2026_08_20_120011_add_supplier_range_index_to_import_histories.php',
            );
            try {
                $migration->down();
                $this->fail('Direct migration down invocation must fail closed.');
            } catch (Throwable $exception) {
                $this->assertStringContainsString(
                    'destructive down must run inside one console command invocation',
                    $exception->getMessage(),
                );
            }
            $this->assertPhaseISchemaUntouched(12, 'direct down');
        } finally {
            $this->resetDownGuard();
            $this->clearDownCapabilityEnvironment();
            DB::setDefaultConnection($originalDefaultConnection);
            DB::disconnect('snapshot_schema_phase_i');
            DB::purge('snapshot_schema_phase_i');
            $this->dropTemporaryDatabase($database);
            File::deleteDirectory($historicalPath);
        }
    }

    public function test_command_completion_reused_input_reentrancy_and_order_fail_closed(): void
    {
        $database = 'phase_i_lifecycle_'.getmypid().'_'.strtolower(bin2hex(random_bytes(4)));
        $historicalPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'_migrations';
        $originalDefaultConnection = DB::getDefaultConnection();
        $output = new BufferedOutput;

        try {
            $this->createTemporaryDatabase($database);
            $this->configureTemporaryConnection($database);
            $this->copyHistoricalMigrations($historicalPath);
            $this->migrateHistoricalThenPhase($historicalPath);
            DB::setDefaultConnection('snapshot_schema_phase_i');
            CanonicalSupplierSnapshotSchema::bootstrapDestructiveDownGuard();

            $input = $this->rollbackArrayInput();
            $completedCapability = $this->issueDownCapability();
            Event::dispatch(new CommandStarting('migrate:rollback', $input, $output));
            Event::dispatch(new CommandFinished('migrate:rollback', $input, $output, 0));
            $this->assertCapabilitySpentAndArtifactAbsent($completedCapability, 'completed command lifecycle');

            try {
                Event::dispatch(new CommandStarting('migrate:rollback', $input, $output));
                $this->fail('A completed command must not authorize reused Input.');
            } catch (Throwable $exception) {
                $this->assertStringContainsString(
                    'one-use invocation capability is missing or malformed',
                    $exception->getMessage(),
                );
            }
            $this->assertPhaseISchemaUntouched(12, 'completed command Input reuse');

            $firstInput = $this->rollbackArrayInput();
            $secondInput = $this->rollbackArrayInput();
            $outerCapability = $this->issueDownCapability();
            Event::dispatch(new CommandStarting('migrate:rollback', $firstInput, $output));
            $nestedCapability = $this->issueDownCapability();
            try {
                Event::dispatch(new CommandStarting('migrate:rollback', $secondInput, $output));
                $this->fail('Nested destructive command scope must fail closed.');
            } catch (Throwable $exception) {
                $this->assertStringContainsString(
                    'nested or re-entrant destructive migration command is not allowed',
                    $exception->getMessage(),
                );
            }
            $this->assertCapabilitySpentAndArtifactAbsent($outerCapability, 'outer nested-command capability');
            $this->assertCapabilitySpentAndArtifactAbsent($nestedCapability, 'nested-command capability');
            $this->assertPhaseISchemaUntouched(12, 'nested command');

            $orderedInput = $this->rollbackArrayInput();
            $orderedCapability = $this->issueDownCapability();
            Event::dispatch(new CommandStarting('migrate:rollback', $orderedInput, $output));
            $invokeWrongStep = static function (ArrayInput $activeInput): void {
                CanonicalSupplierSnapshotSchema::runDestructiveDownStep(
                    '2026_08_20_120010_create_supplier_offer_snapshot_observations_table',
                    static function (): void {},
                );
            };
            try {
                $invokeWrongStep($orderedInput);
                $this->fail('Out-of-order Phase I down step must fail closed.');
            } catch (Throwable $exception) {
                $this->assertStringContainsString('unexpected rollback step', $exception->getMessage());
            }
            $this->assertCapabilitySpentAndArtifactAbsent($orderedCapability, 'out-of-order rollback step');
            $this->assertPhaseISchemaUntouched(12, 'out-of-order migration');
        } finally {
            $this->resetDownGuard();
            $this->clearDownCapabilityEnvironment();
            DB::setDefaultConnection($originalDefaultConnection);
            DB::disconnect('snapshot_schema_phase_i');
            DB::purge('snapshot_schema_phase_i');
            $this->dropTemporaryDatabase($database);
            File::deleteDirectory($historicalPath);
        }
    }

    public function test_capability_artifact_is_private_strict_and_atomically_one_use(): void
    {
        $firstToken = $this->issueDownCapability();
        $secondToken = $this->issueDownCapability();
        $firstDirectory = $this->capabilityDirectory($firstToken);
        $secondDirectory = $this->capabilityDirectory($secondToken);
        $this->assertNotSame($firstDirectory, $secondDirectory);
        $this->assertMatchesRegularExpression('/[\\\\\/]mycomputer-phase-i-down[\\\\\/][0-9a-f]{64}$/', $firstDirectory);
        $this->assertCapabilityPathSecurity($this->capabilityPath($firstToken), $firstDirectory);
        $this->assertNotSame(dirname($firstDirectory), $this->capabilityLedgerRoot());
        $this->assertCapabilityPathSecurity(
            $this->capabilityIssuedLedgerPath($firstToken),
            $this->capabilityLedgerRoot(),
        );
        $this->assertFalse(file_exists($this->capabilitySpentLedgerPath($firstToken)));
        CanonicalSupplierSnapshotSchema::revokeDestructiveDownCapability($firstToken);
        CanonicalSupplierSnapshotSchema::revokeDestructiveDownCapability($secondToken);
        $this->assertCapabilitySpentAndArtifactAbsent($firstToken, 'explicit first capability revoke');
        $this->assertCapabilitySpentAndArtifactAbsent($secondToken, 'explicit second capability revoke');

        $token = $this->issueDownCapability();
        file_put_contents($this->capabilityPath($token), '{invalid-json');
        $this->assertCapabilityConsumptionRejectedAndCleaned($token, 'Syntax error', 'invalid JSON');

        $payloadMutations = [
            'extra key' => static function (array $payload): array {
                $payload['unexpected'] = true;

                return $payload;
            },
            'missing key' => static function (array $payload): array {
                unset($payload['expires_at']);

                return $payload;
            },
            'wrong version' => static function (array $payload): array {
                $payload['version'] = 'wrong-version';

                return $payload;
            },
            'wrong plan' => static function (array $payload): array {
                $payload['rollback_plan_sha256'] = str_repeat('b', 64);

                return $payload;
            },
            'wrong token' => static function (array $payload): array {
                $payload['token_sha256'] = str_repeat('c', 64);

                return $payload;
            },
            'expired' => static function (array $payload): array {
                $payload['expires_at'] = time() - 1;

                return $payload;
            },
            'extended expiry' => static function (array $payload): array {
                $payload['expires_at'] += 3600;

                return $payload;
            },
            'wrong issuance' => static function (array $payload): array {
                $payload['issuance_id'] = str_repeat('d', 64);

                return $payload;
            },
        ];

        foreach ($payloadMutations as $label => $mutation) {
            $token = $this->issueDownCapability();
            $path = $this->capabilityPath($token);
            $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($payload);
            file_put_contents(
                $path,
                json_encode($mutation($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            );
            $this->assertCapabilityConsumptionRejectedAndCleaned($token, 'invalid or expired', $label);
        }

        $token = $this->issueDownCapability();
        $path = $this->capabilityPath($token);
        unlink($path);
        mkdir($path);
        $this->assertCapabilityConsumptionRejectedAndCleaned($token, 'not a regular file', 'non-regular artifact');

        $token = $this->issueDownCapability();
        $path = $this->capabilityPath($token);
        $linkTarget = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phase-i-capability-link-'.bin2hex(random_bytes(8));
        unlink($path);
        if (PHP_OS_FAMILY === 'Windows') {
            mkdir($linkTarget, 0700);
            $process = new Process([
                getenv('ComSpec') ?: 'C:\\Windows\\System32\\cmd.exe',
                '/d',
                '/c',
                'mklink',
                '/J',
                $path,
                $linkTarget,
            ]);
            $process->run();
            $this->assertTrue($process->isSuccessful(), 'Windows junction creation must be available for reparse testing.');
        } else {
            file_put_contents($linkTarget, 'target');
            $this->assertTrue(symlink($linkTarget, $path));
        }
        $this->assertCapabilityConsumptionRejectedAndCleaned($token, 'Capability', 'symlink/reparse artifact');
        if (is_dir($linkTarget)) {
            rmdir($linkTarget);
        } else {
            unlink($linkTarget);
        }

        $token = $this->issueDownCapability();
        $directory = $this->capabilityDirectory($token);
        $this->makeCapabilityPathUnsafe($directory, true);
        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertIndependentWindowsAclUnsafe($directory, true);
        }
        $this->assertCapabilityConsumptionRejectedAndCleaned(
            $token,
            PHP_OS_FAMILY === 'Windows' ? 'Windows capability ACL' : 'permissions are not exactly 0700',
            'unsafe parent permissions',
        );

        $token = $this->issueDownCapability();
        $path = $this->capabilityPath($token);
        $this->makeCapabilityPathUnsafe($path, false);
        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertIndependentWindowsAclUnsafe($path, false);
        }
        $this->assertCapabilityConsumptionRejectedAndCleaned(
            $token,
            PHP_OS_FAMILY === 'Windows' ? 'Windows capability ACL' : 'permissions are not exactly 0600',
            'unsafe artifact permissions',
        );

        $token = $this->issueDownCapability();
        $issuedPath = $this->capabilityIssuedLedgerPath($token);
        $this->makeCapabilityPathUnsafe($issuedPath, false);
        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertIndependentWindowsAclUnsafe($issuedPath, false);
        }
        try {
            $this->invokeCapabilityMethod('consumeInvocationCapability');
            $this->fail('Unsafe authoritative ledger ACL must fail closed.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString(
                PHP_OS_FAMILY === 'Windows' ? 'Windows capability ACL' : 'permissions are not exactly 0600',
                $exception->getMessage(),
            );
        }
        $this->assertFalse(file_exists($this->capabilityDirectory($token)));
        $this->assertFalse(file_exists($this->capabilitySpentLedgerPath($token)));
        $this->restoreIndependentPathSecurity($issuedPath, false);
        CanonicalSupplierSnapshotSchema::revokeDestructiveDownCapability($token);
        $this->assertCapabilitySpentAndArtifactAbsent($token, 'unsafe issued ledger ACL');

        $token = $this->issueDownCapability();
        $issuedPath = $this->capabilityIssuedLedgerPath($token);
        $issuedRaw = (string) file_get_contents($issuedPath);
        $issuedPayload = json_decode($issuedRaw, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($issuedPayload);
        $issuedPayload['unexpected'] = true;
        file_put_contents(
            $issuedPath,
            json_encode($issuedPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        try {
            $this->invokeCapabilityMethod('consumeInvocationCapability');
            $this->fail('An issued ledger record with unknown keys must fail closed.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString('authoritative issued ledger record is invalid', $exception->getMessage());
        }
        $this->assertFalse(file_exists($this->capabilityDirectory($token)));
        $this->assertFalse(file_exists($this->capabilitySpentLedgerPath($token)));
        file_put_contents($issuedPath, $issuedRaw);
        CanonicalSupplierSnapshotSchema::revokeDestructiveDownCapability($token);
        $this->assertCapabilitySpentAndArtifactAbsent($token, 'strict issued ledger payload');

        $token = $this->issueDownCapability();
        $issuedPath = $this->capabilityIssuedLedgerPath($token);
        $ledgerLinkTarget = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phase-i-ledger-link-'.bin2hex(random_bytes(8));
        unlink($issuedPath);
        if (PHP_OS_FAMILY === 'Windows') {
            mkdir($ledgerLinkTarget, 0700);
            $process = new Process([
                getenv('ComSpec') ?: 'C:\\Windows\\System32\\cmd.exe',
                '/d',
                '/c',
                'mklink',
                '/J',
                $issuedPath,
                $ledgerLinkTarget,
            ]);
            $process->run();
            $this->assertTrue($process->isSuccessful(), 'Windows ledger junction creation must be available.');
        } else {
            file_put_contents($ledgerLinkTarget, 'target');
            $this->assertTrue(symlink($ledgerLinkTarget, $issuedPath));
        }
        try {
            $this->invokeCapabilityMethod('consumeInvocationCapability');
            $this->fail('A ledger symlink or reparse point must fail closed.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString('Capability', $exception->getMessage());
        }
        $this->assertFalse(file_exists($this->capabilityDirectory($token)));
        if (PHP_OS_FAMILY === 'Windows') {
            rmdir($issuedPath);
            rmdir($ledgerLinkTarget);
        } else {
            unlink($issuedPath);
            unlink($ledgerLinkTarget);
        }
        $this->assertFalse(file_exists($this->capabilitySpentLedgerPath($token)));

        $token = $this->issueDownCapability();
        $worker = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phase-i-capability-consumer-'.bin2hex(random_bytes(8)).'.php';
        File::put($worker, <<<'PHP'
            <?php
            require $argv[1].'/vendor/autoload.php';
            require_once $argv[1].'/database/migrations/support/CanonicalSupplierSnapshotSchema.php';
            try {
                $reflection = new ReflectionClass(Database\Migrations\Support\CanonicalSupplierSnapshotSchema::class);
                $method = $reflection->getMethod('consumeInvocationCapability');
                $method->invoke(null);
                fwrite(STDOUT, 'CONSUMED');
                exit(0);
            } catch (Throwable) {
                fwrite(STDOUT, 'REJECTED');
                exit(2);
            }
            PHP);
        try {
            $processes = [
                new Process([PHP_BINARY, $worker, base_path()], env: [self::DOWN_CAPABILITY_ENV => $token]),
                new Process([PHP_BINARY, $worker, base_path()], env: [self::DOWN_CAPABILITY_ENV => $token]),
            ];
            foreach ($processes as $process) {
                $process->setTimeout(20);
                $process->start();
            }
            foreach ($processes as $process) {
                $process->wait();
            }
            $this->assertSame(1, collect($processes)->filter(
                static fn (Process $process): bool => $process->getExitCode() === 0
                    && $process->getOutput() === 'CONSUMED',
            )->count());
            $this->assertSame(1, collect($processes)->filter(
                static fn (Process $process): bool => $process->getExitCode() === 2
                    && $process->getOutput() === 'REJECTED',
            )->count());
            $this->assertDirectoryExists(
                $this->capabilityDirectory($token),
                'The losing consumer must not delete the winning consumer namespace.',
            );
            $this->assertFileExists($this->capabilitySpentLedgerPath($token));
            CanonicalSupplierSnapshotSchema::revokeDestructiveDownCapability($token);
            $this->assertCapabilitySpentAndArtifactAbsent($token, 'concurrent capability consumption');
        } finally {
            File::delete($worker);
            CanonicalSupplierSnapshotSchema::revokeDestructiveDownCapability($token);
        }
    }

    public function test_rejected_capability_cannot_be_reconstructed_or_extended_to_authorize_rollback(): void
    {
        $database = 'phase_i_replay_'.getmypid().'_'.strtolower(bin2hex(random_bytes(4)));
        $historicalPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'_migrations';
        $originalDefaultConnection = DB::getDefaultConnection();
        $token = null;

        try {
            $this->createTemporaryDatabase($database);
            $this->configureTemporaryConnection($database);
            $this->copyHistoricalMigrations($historicalPath);
            $this->migrateHistoricalThenPhase($historicalPath);
            DB::setDefaultConnection('snapshot_schema_phase_i');

            $token = $this->issueDownCapability();
            $originalArtifact = (string) file_get_contents($this->capabilityPath($token));
            file_put_contents($this->capabilityPath($token), '{invalid-json');

            $initial = $this->rollbackAttempt($token);
            $this->assertNotSame(0, $initial['exit']);
            $this->assertStringContainsString('Syntax error', $initial['message']);
            $this->assertCapabilitySpentAndArtifactAbsent($token, 'rejected replay fixture');
            $this->assertPhaseISchemaUntouched(12, 'initial malformed capability rejection');

            $this->recreateArtifactFixture($token, $originalArtifact);
            $replay = $this->rollbackAttempt($token);
            $this->assertNotSame(0, $replay['exit']);
            $this->assertStringContainsString('already spent or revoked', $replay['message']);
            $this->assertPhaseISchemaUntouched(12, 'reconstructed old-token replay');

            $extended = json_decode($originalArtifact, true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($extended);
            $extended['expires_at'] += 3600;
            file_put_contents(
                $this->capabilityPath($token),
                json_encode($extended, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            );
            $extendedReplay = $this->rollbackAttempt($token);
            $this->assertNotSame(0, $extendedReplay['exit']);
            $this->assertStringContainsString('already spent or revoked', $extendedReplay['message']);
            $this->assertPhaseISchemaUntouched(12, 'extended-expiry old-token replay');

            $this->assertTrue(is_file($this->capabilityIssuedLedgerPath($token)));
            $this->assertTrue(is_file($this->capabilitySpentLedgerPath($token)));
            $this->assertSame(10, collect(self::CANONICAL_TABLES)->filter(
                static fn (string $table): bool => Schema::hasTable($table),
            )->count());
            $this->assertSame(
                12,
                DB::table('migrations')->whereIn('migration', self::PHASE_I_MIGRATIONS)->count(),
            );
        } finally {
            if (is_string($token)) {
                CanonicalSupplierSnapshotSchema::revokeDestructiveDownCapability($token);
            }
            $this->resetDownGuard();
            $this->clearDownCapabilityEnvironment();
            DB::setDefaultConnection($originalDefaultConnection);
            DB::disconnect('snapshot_schema_phase_i');
            DB::purge('snapshot_schema_phase_i');
            $this->dropTemporaryDatabase($database);
            File::deleteDirectory($historicalPath);
        }
    }

    public function test_missing_artifact_is_revoked_and_missing_issuance_never_authorizes(): void
    {
        $missingArtifactToken = $this->issueDownCapability();
        $originalArtifact = (string) file_get_contents($this->capabilityPath($missingArtifactToken));
        unlink($this->capabilityPath($missingArtifactToken));

        try {
            $this->invokeCapabilityMethod('consumeInvocationCapability');
            $this->fail('A missing artifact must fail after atomically spending its capability.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString('missing after consumption claim', $exception->getMessage());
        }
        $this->assertCapabilitySpentAndArtifactAbsent($missingArtifactToken, 'missing artifact');

        $this->recreateArtifactFixture($missingArtifactToken, $originalArtifact);
        $this->presentDownCapability($missingArtifactToken);
        try {
            $this->invokeCapabilityMethod('consumeInvocationCapability');
            $this->fail('A reconstructed missing artifact capability must remain spent.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString('already spent or revoked', $exception->getMessage());
        }
        $this->assertTrue(
            is_dir($this->capabilityDirectory($missingArtifactToken)),
            'Spent loser handling must not delete a possible winning consumer namespace.',
        );
        CanonicalSupplierSnapshotSchema::revokeDestructiveDownCapability($missingArtifactToken);
        $this->assertCapabilitySpentAndArtifactAbsent($missingArtifactToken, 'missing artifact replay cleanup');

        $missingIssuedToken = $this->issueDownCapability();
        $missingIssuedArtifact = (string) file_get_contents($this->capabilityPath($missingIssuedToken));
        unlink($this->capabilityIssuedLedgerPath($missingIssuedToken));
        try {
            $this->invokeCapabilityMethod('consumeInvocationCapability');
            $this->fail('An artifact without authoritative issuance must fail closed.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString('authoritative issued ledger record is missing', $exception->getMessage());
        }
        $this->assertUnissuedCapabilityStateAbsent($missingIssuedToken, 'missing authoritative issuance');

        $this->recreateArtifactFixture($missingIssuedToken, $missingIssuedArtifact);
        $this->presentDownCapability($missingIssuedToken);
        try {
            $this->invokeCapabilityMethod('consumeInvocationCapability');
            $this->fail('Recreated artifact state must not replace authoritative issuance.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString('authoritative issued ledger record is missing', $exception->getMessage());
        }
        $this->assertUnissuedCapabilityStateAbsent($missingIssuedToken, 'recreated artifact without issuance');
        $this->assertSame(10, collect(self::CANONICAL_TABLES)->filter(
            static fn (string $table): bool => Schema::hasTable($table),
        )->count());
    }

    private function assertIndexInventory(): void
    {
        $expected = [
            'supplier_import_execution_claims' => [
                'PRIMARY', 'ix_import_execution_claim_feed',
                'ix_import_execution_claim_job_owner_fk',
                'ix_import_execution_claim_scope_state',
                'ix_import_execution_claim_supplier',
                'uq_import_execution_claim_history', 'uq_import_execution_claim_id_key',
                'uq_import_execution_claim_job', 'uq_import_execution_claim_logical_key',
                'uq_import_execution_claim_run',
            ],
            'supplier_import_dispatch_outbox' => [
                'PRIMARY', 'ix_import_dispatch_outbox_due',
                'ix_import_dispatch_outbox_lease',
                'ix_import_dispatch_outbox_state_watchdog_id',
                'uq_import_dispatch_outbox_claim_event',
                'uq_import_dispatch_outbox_claim_key',
                'uq_import_dispatch_outbox_id_claim',
            ],
            'supplier_import_dispatch_monitor_health' => [
                'PRIMARY', 'uq_import_dispatch_monitor_identity',
                'uq_import_dispatch_observer_identity',
            ],
            'supplier_import_dispatch_alert_intents' => [
                'PRIMARY', 'ix_import_dispatch_alert_due',
                'ix_import_dispatch_alert_lease', 'ix_import_dispatch_alert_outbox',
                'uq_import_dispatch_alert_identity',
            ],
            'supplier_import_dispatch_recovery_authorizations' => [
                'PRIMARY', 'ix_import_recovery_auth_claim',
                'ix_import_recovery_auth_operator', 'ix_import_recovery_auth_outbox_claim',
                'uq_import_recovery_auth_complete_tuple', 'uq_import_recovery_auth_nonce',
            ],
            'supplier_import_dispatch_recovery_results' => [
                'PRIMARY', 'ix_import_recovery_result_claim',
                'ix_import_recovery_result_complete_auth_tuple',
                'ix_import_recovery_result_operator',
                'ix_import_recovery_result_outbox_claim',
                'uq_import_recovery_result_auth_sequence',
                'uq_import_recovery_result_auth_started',
                'uq_import_recovery_result_auth_terminal',
            ],
            'supplier_import_cohort_authorization_members' => [
                'PRIMARY', 'uq_import_cohort_auth_claim_offer',
            ],
            'supplier_offer_snapshot_generations' => [
                'PRIMARY', 'ix_snapshot_generation_feed',
                'ix_snapshot_generation_feed_import',
                'ix_snapshot_generation_predecessor',
                'ix_snapshot_generation_qualified_range',
                'ix_snapshot_generation_retention',
                'ix_snapshot_generation_scope_order',
                'uq_snapshot_generation_execution_claim',
                'uq_snapshot_generation_import_history',
            ],
            'supplier_offer_snapshot_enrollments' => [
                'PRIMARY', 'ix_snapshot_enrollment_effective',
                'ix_snapshot_enrollment_effective_history',
                'ix_snapshot_enrollment_feed', 'uq_snapshot_enrollment_scope_offer',
            ],
            'supplier_offer_snapshot_observations' => [
                'PRIMARY', 'ix_snapshot_observation_enrollment_history',
                'ix_snapshot_observation_offer_history',
                'uq_snapshot_observation_generation_enrollment',
                'uq_snapshot_observation_generation_offer',
            ],
        ];

        foreach ($expected as $table => $names) {
            sort($names);
            $actual = collect(DB::select(<<<'SQL'
                SELECT DISTINCT INDEX_NAME AS index_name
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                ORDER BY INDEX_NAME
                SQL, [$table]))->pluck('index_name')->all();
            sort($actual);
            $this->assertSame($names, $actual, "Unexpected index inventory for {$table}.");
        }

        $this->assertTrue($this->indexExists('import_jobs', 'uq_import_job_id_supplier_feed'));
        $this->assertTrue($this->indexExists('import_histories', 'ix_import_history_supplier_id'));
    }

    private function assertForeignKeyInventory(): void
    {
        $expected = [
            'fk_import_cohort_auth_claim',
            'fk_import_dispatch_alert_outbox',
            'fk_import_dispatch_outbox_claim',
            'fk_import_dispatch_outbox_claim_key',
            'fk_import_execution_claim_feed',
            'fk_import_execution_claim_history',
            'fk_import_execution_claim_job_scope',
            'fk_import_execution_claim_run',
            'fk_import_execution_claim_supplier',
            'fk_import_recovery_auth_claim',
            'fk_import_recovery_auth_operator',
            'fk_import_recovery_auth_outbox_claim',
            'fk_import_recovery_result_auth',
            'fk_import_recovery_result_complete_auth_tuple',
            'fk_snapshot_enrollment_effective_history',
            'fk_snapshot_enrollment_feed',
            'fk_snapshot_enrollment_supplier',
            'fk_snapshot_generation_execution_claim',
            'fk_snapshot_generation_feed',
            'fk_snapshot_generation_import_history',
            'fk_snapshot_generation_predecessor',
            'fk_snapshot_generation_supplier',
            'fk_snapshot_observation_enrollment',
            'fk_snapshot_observation_generation',
        ];
        sort($expected);

        $rows = collect(DB::select(<<<'SQL'
            SELECT CONSTRAINT_NAME AS constraint_name,
                   UPDATE_RULE AS update_rule,
                   DELETE_RULE AS delete_rule
            FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
                AND TABLE_NAME IN (
                    'supplier_import_execution_claims',
                    'supplier_import_dispatch_outbox',
                    'supplier_import_dispatch_monitor_health',
                    'supplier_import_dispatch_alert_intents',
                    'supplier_import_dispatch_recovery_authorizations',
                    'supplier_import_dispatch_recovery_results',
                    'supplier_import_cohort_authorization_members',
                    'supplier_offer_snapshot_generations',
                    'supplier_offer_snapshot_enrollments',
                    'supplier_offer_snapshot_observations'
                )
            ORDER BY CONSTRAINT_NAME
            SQL));

        $this->assertSame($expected, $rows->pluck('constraint_name')->all());
        $this->assertSame(['RESTRICT'], $rows->pluck('update_rule')->unique()->values()->all());
        $this->assertSame(['RESTRICT'], $rows->pluck('delete_rule')->unique()->values()->all());

        $composite = collect(DB::select(<<<'SQL'
            SELECT CONSTRAINT_NAME AS constraint_name,
                   GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION SEPARATOR ',') AS child_columns,
                   GROUP_CONCAT(REFERENCED_COLUMN_NAME ORDER BY ORDINAL_POSITION SEPARATOR ',') AS parent_columns
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE CONSTRAINT_SCHEMA = DATABASE()
                AND CONSTRAINT_NAME IN (
                    'fk_import_execution_claim_job_scope',
                    'fk_import_dispatch_outbox_claim_key',
                    'fk_import_recovery_auth_outbox_claim',
                    'fk_import_recovery_result_complete_auth_tuple'
                )
            GROUP BY CONSTRAINT_NAME
            ORDER BY CONSTRAINT_NAME
            SQL))->keyBy('constraint_name');

        $this->assertSame(
            'import_job_id,supplier_id,supplier_feed_id',
            $composite['fk_import_execution_claim_job_scope']->child_columns,
        );
        $this->assertSame(
            'supplier_import_execution_claim_id,logical_execution_key',
            $composite['fk_import_dispatch_outbox_claim_key']->child_columns,
        );
        $this->assertSame(
            'supplier_import_dispatch_outbox_id,supplier_import_execution_claim_id',
            $composite['fk_import_recovery_auth_outbox_claim']->child_columns,
        );
        $this->assertSame(
            'supplier_import_dispatch_recovery_authorization_id,authorization_action,authorized_operator_id,supplier_import_execution_claim_id,supplier_import_dispatch_outbox_id,logical_execution_key,target_parent_type,target_parent_id',
            $composite['fk_import_recovery_result_complete_auth_tuple']->child_columns,
        );
        $this->assertSame(
            'id,supplier_id,supplier_feed_id',
            $composite['fk_import_execution_claim_job_scope']->parent_columns,
        );
        $this->assertSame(
            'id,logical_execution_key',
            $composite['fk_import_dispatch_outbox_claim_key']->parent_columns,
        );
        $this->assertSame(
            'id,supplier_import_execution_claim_id',
            $composite['fk_import_recovery_auth_outbox_claim']->parent_columns,
        );
        $this->assertSame(
            'id,authorization_action,authorized_operator_id,supplier_import_execution_claim_id,supplier_import_dispatch_outbox_id,logical_execution_key,target_parent_type,target_parent_id',
            $composite['fk_import_recovery_result_complete_auth_tuple']->parent_columns,
        );
    }

    private function assertCheckInventory(): void
    {
        $expected = [];
        foreach (self::EXPECTED_CHECKS as $table => $checks) {
            foreach ($checks as $name => $expression) {
                $expected[] = [
                    'table_name' => $table,
                    'constraint_name' => $name,
                    'expression' => self::normalizeSql($expression),
                ];
            }
        }

        $actual = collect(DB::select(<<<'SQL'
            SELECT tc.TABLE_NAME AS table_name,
                   tc.CONSTRAINT_NAME AS constraint_name,
                   cc.CHECK_CLAUSE AS expression
            FROM information_schema.TABLE_CONSTRAINTS tc
            INNER JOIN information_schema.CHECK_CONSTRAINTS cc
                ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
                AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
            WHERE tc.CONSTRAINT_SCHEMA = DATABASE()
                AND tc.CONSTRAINT_TYPE = 'CHECK'
                AND tc.TABLE_NAME IN (
                    'supplier_import_execution_claims',
                    'supplier_import_dispatch_outbox',
                    'supplier_import_dispatch_monitor_health',
                    'supplier_import_dispatch_alert_intents',
                    'supplier_import_dispatch_recovery_authorizations',
                    'supplier_import_dispatch_recovery_results',
                    'supplier_import_cohort_authorization_members',
                    'supplier_offer_snapshot_generations',
                    'supplier_offer_snapshot_enrollments',
                    'supplier_offer_snapshot_observations'
                )
            ORDER BY tc.TABLE_NAME, tc.CONSTRAINT_NAME
            SQL))->map(static fn (object $row): array => [
            'table_name' => (string) $row->table_name,
            'constraint_name' => (string) $row->constraint_name,
            'expression' => self::normalizeSql((string) $row->expression),
        ])->all();

        $this->assertCount(94, $expected);
        $this->assertCount(94, $actual);
        $this->assertSame($expected, $actual);
    }

    private function assertTriggerInventory(): void
    {
        $expected = array_map(static fn (array $row): array => [
            ...$row,
            'statement' => self::normalizeSql($row['statement']),
        ], self::EXPECTED_TRIGGERS);

        $actual = collect(DB::select(<<<'SQL'
            SELECT TRIGGER_NAME AS trigger_name,
                   EVENT_OBJECT_TABLE AS table_name,
                   ACTION_TIMING AS timing,
                   EVENT_MANIPULATION AS event,
                   ACTION_STATEMENT AS statement
            FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE()
                AND EVENT_OBJECT_TABLE IN (
                    'supplier_import_execution_claims',
                    'supplier_import_dispatch_outbox',
                    'supplier_import_dispatch_monitor_health',
                    'supplier_import_dispatch_alert_intents',
                    'supplier_import_dispatch_recovery_authorizations',
                    'supplier_import_dispatch_recovery_results',
                    'supplier_import_cohort_authorization_members',
                    'supplier_offer_snapshot_generations',
                    'supplier_offer_snapshot_enrollments',
                    'supplier_offer_snapshot_observations'
                )
            ORDER BY TRIGGER_NAME
            SQL))->map(static fn (object $row): array => [
            'trigger_name' => (string) $row->trigger_name,
            'table_name' => (string) $row->table_name,
            'timing' => (string) $row->timing,
            'event' => (string) $row->event,
            'statement' => self::normalizeSql((string) $row->statement),
        ])->all();

        $this->assertCount(13, $actual);
        $this->assertSame($expected, $actual);
    }

    private function assertGeneratedGuardInventory(): void
    {
        $rows = collect(DB::select(<<<'SQL'
            SELECT c.TABLE_NAME AS table_name,
                   c.COLUMN_NAME AS column_name,
                   c.COLUMN_TYPE AS column_type,
                   c.IS_NULLABLE AS is_nullable,
                   c.EXTRA AS extra,
                   c.GENERATION_EXPRESSION AS expression,
                   s.INDEX_NAME AS index_name,
                   s.NON_UNIQUE AS non_unique,
                   (
                       SELECT GROUP_CONCAT(s2.COLUMN_NAME ORDER BY s2.SEQ_IN_INDEX SEPARATOR ',')
                       FROM information_schema.STATISTICS s2
                       WHERE s2.TABLE_SCHEMA = s.TABLE_SCHEMA
                           AND s2.TABLE_NAME = s.TABLE_NAME
                           AND s2.INDEX_NAME = s.INDEX_NAME
                   ) AS index_columns
            FROM information_schema.COLUMNS c
            INNER JOIN information_schema.STATISTICS s
                ON s.TABLE_SCHEMA = c.TABLE_SCHEMA
                AND s.TABLE_NAME = c.TABLE_NAME
                AND s.COLUMN_NAME = c.COLUMN_NAME
            WHERE c.TABLE_SCHEMA = DATABASE()
                AND c.TABLE_NAME = 'supplier_import_dispatch_recovery_results'
                AND c.COLUMN_NAME IN ('started_once_guard', 'terminal_once_guard')
            ORDER BY c.COLUMN_NAME, s.INDEX_NAME, s.SEQ_IN_INDEX
            SQL))->map(static fn (object $row): array => [
            'table_name' => (string) $row->table_name,
            'column_name' => (string) $row->column_name,
            'column_type' => (string) $row->column_type,
            'is_nullable' => (string) $row->is_nullable,
            'extra' => (string) $row->extra,
            'expression' => self::normalizeSql((string) $row->expression),
            'index_name' => (string) $row->index_name,
            'non_unique' => (int) $row->non_unique,
            'index_columns' => explode(',', (string) $row->index_columns),
        ])->all();

        $this->assertCount(2, $rows);
        $expected = array_map(static fn (array $row): array => [
            ...$row,
            'expression' => self::normalizeSql($row['expression']),
        ], self::EXPECTED_GENERATED_GUARDS);
        $this->assertSame($expected, $rows);
    }

    private function assertHexColumnInventory(): void
    {
        $rows = collect(DB::select(<<<'SQL'
            SELECT TABLE_NAME AS table_name,
                   COLUMN_NAME AS column_name,
                   COLUMN_TYPE AS column_type,
                   CHARACTER_MAXIMUM_LENGTH AS length,
                   CHARACTER_SET_NAME AS charset,
                   COLLATION_NAME AS collation,
                   IS_NULLABLE AS is_nullable
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
                AND COLUMN_TYPE = 'char(64)'
                AND CHARACTER_SET_NAME = 'ascii'
                AND COLLATION_NAME = 'ascii_bin'
                AND TABLE_NAME IN (
                    'supplier_import_execution_claims',
                    'supplier_import_dispatch_outbox',
                    'supplier_import_dispatch_monitor_health',
                    'supplier_import_dispatch_alert_intents',
                    'supplier_import_dispatch_recovery_authorizations',
                    'supplier_import_dispatch_recovery_results',
                    'supplier_import_cohort_authorization_members',
                    'supplier_offer_snapshot_generations',
                    'supplier_offer_snapshot_enrollments',
                    'supplier_offer_snapshot_observations'
                )
            ORDER BY TABLE_NAME, COLUMN_NAME
            SQL))->map(static fn (object $row): array => [
            'table_name' => (string) $row->table_name,
            'column_name' => (string) $row->column_name,
            'column_type' => (string) $row->column_type,
            'length' => (int) $row->length,
            'charset' => (string) $row->charset,
            'collation' => (string) $row->collation,
            'is_nullable' => (string) $row->is_nullable,
        ])->all();

        $this->assertCount(27, $rows);
        $this->assertSame(self::SECURITY_COLUMNS, collect($rows)->mapWithKeys(
            static fn (array $row): array => [
                $row['table_name'].'.'.$row['column_name'] => $row['is_nullable'],
            ],
        )->all());

        foreach ($rows as $row) {
            if ($row['table_name'] === 'supplier_import_dispatch_recovery_results'
                && $row['column_name'] === 'logical_execution_key') {
                $this->assertSame(1, (int) DB::scalar(<<<'SQL'
                    SELECT COUNT(*)
                    FROM information_schema.REFERENTIAL_CONSTRAINTS
                    WHERE CONSTRAINT_SCHEMA = DATABASE()
                        AND TABLE_NAME = 'supplier_import_dispatch_recovery_results'
                        AND CONSTRAINT_NAME = 'fk_import_recovery_result_complete_auth_tuple'
                        AND REFERENCED_TABLE_NAME = 'supplier_import_dispatch_recovery_authorizations'
                        AND UPDATE_RULE = 'RESTRICT'
                        AND DELETE_RULE = 'RESTRICT'
                    SQL));

                continue;
            }

            $checks = collect(DB::select(<<<'SQL'
                SELECT cc.CHECK_CLAUSE AS expression
                FROM information_schema.TABLE_CONSTRAINTS tc
                INNER JOIN information_schema.CHECK_CONSTRAINTS cc
                    ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
                    AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
                WHERE tc.CONSTRAINT_SCHEMA = DATABASE() AND tc.TABLE_NAME = ?
                SQL, [$row['table_name']]))->pluck('expression');
            $this->assertTrue($checks->contains(
                static fn (string $expression): bool => str_contains($expression, '`'.$row['column_name'].'`')
                    && str_contains(strtolower($expression), 'regexp_like'),
            ), 'Missing exact hexadecimal CHECK coverage for '.$row['table_name'].'.'.$row['column_name']);
        }
    }

    private function assertPristineMonitor(): void
    {
        $row = (array) DB::table('supplier_import_dispatch_monitor_health')->sole();
        $this->assertSame(1, (int) $row['id']);
        $this->assertSame('supplier-import-dispatch-watchdog-v1', $row['monitor_identity']);
        $this->assertSame('supplier-import-dispatch-observer-v1', $row['observer_identity']);
        $this->assertSame('unknown', $row['integrity_state']);
        foreach ([
            'monitor_generation', 'last_successful_monitor_generation',
            'cycle_sequence', 'observer_sequence', 'observed_monitor_generation',
            'observed_cycle_sequence',
        ] as $column) {
            $this->assertSame(0, (int) $row[$column]);
        }
        foreach ([
            'monitor_owner_token_hash', 'monitor_lease_acquired_at',
            'monitor_lease_expires_at', 'last_successful_cycle_at',
            'last_successful_sink_health_at', 'last_successful_sink_contract_key',
            'last_successful_observer_at', 'last_failure_code',
        ] as $column) {
            $this->assertNull($row[$column]);
        }
    }

    /** @return array<string, int> */
    private function seedParentFixture(string $suffix = 'primary', string $connection = 'mysql'): array
    {
        $db = DB::connection($connection);
        $now = Carbon::parse('2026-08-20 08:00:00', 'UTC');
        $supplierId = $db->table('suppliers')->insertGetId([
            'company_name' => 'Schema Supplier '.$suffix,
            'slug' => 'schema-supplier-'.$suffix.'-'.strtolower(bin2hex(random_bytes(3))),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $feedId = $db->table('supplier_feeds')->insertGetId([
            'supplier_id' => $supplierId,
            'feed_name' => 'Schema Feed '.$suffix,
            'feed_url' => 'https://example.test/'.$suffix.'.xml',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $jobId = $db->table('import_jobs')->insertGetId([
            'supplier_id' => $supplierId,
            'supplier_feed_id' => $feedId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $runId = $db->table('supplier_import_runs')->insertGetId([
            'supplier_id' => $supplierId,
            'supplier_feed_id' => $feedId,
            'import_job_id' => $jobId,
            'trigger_type' => 'manual',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $historyId = $db->table('import_histories')->insertGetId([
            'import_job_id' => $jobId,
            'supplier_id' => $supplierId,
            'supplier_feed_id' => $feedId,
            'event' => 'started',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return compact('supplierId', 'feedId', 'jobId', 'runId', 'historyId') + [
            'supplier_id' => $supplierId,
            'feed_id' => $feedId,
            'job_id' => $jobId,
            'run_id' => $runId,
            'history_id' => $historyId,
        ];
    }

    /** @return array<string, int> */
    private function seedProtectedGraph(string $connection = 'mysql'): array
    {
        $db = DB::connection($connection);
        $parents = $this->seedParentFixture('graph', $connection);
        $userId = $db->table('users')->insertGetId([
            'name' => 'Schema Operator',
            'email' => 'schema-operator-'.strtolower(bin2hex(random_bytes(4))).'@example.test',
            'password' => 'not-used',
            'created_at' => '2026-08-20 08:00:00',
            'updated_at' => '2026-08-20 08:00:00',
        ]);
        $logicalKey = str_repeat('a', 64);
        $claimId = $db->table('supplier_import_execution_claims')->insertGetId([
            'logical_execution_key' => $logicalKey,
            'supplier_id' => $parents['supplier_id'],
            'supplier_feed_id' => $parents['feed_id'],
            'import_job_id' => $parents['job_id'],
            'allocated_at' => '2026-08-20 08:00:00.000000',
            'import_history_id' => $parents['history_id'],
            'execution_path' => 'legacy_xml',
        ]);
        $outboxId = $db->table('supplier_import_dispatch_outbox')->insertGetId([
            'supplier_import_execution_claim_id' => $claimId,
            'logical_execution_key' => $logicalKey,
            'event_type' => 'initial_dispatch',
            'job_type' => 'process_xml_supplier_feed',
            'dispatch_payload' => '{}',
            'dispatch_payload_hash' => str_repeat('b', 64),
            'transport_deadline_at' => '2026-08-21 08:00:00.000000',
            'created_at' => '2026-08-20 08:00:00.000000',
            'updated_at' => '2026-08-20 08:00:00.000000',
        ]);
        $alertId = $db->table('supplier_import_dispatch_alert_intents')->insertGetId([
            'alert_identity' => str_repeat('c', 64),
            'schema_version' => 'supplier-import-dispatch-alert-v1',
            'alert_type' => 'dispatch_watchdog_overdue',
            'dispatch_outbox_id' => $outboxId,
            'delivery_watchdog_at' => '2026-08-20 09:00:00.000000',
            'severity' => 'warning',
            'payload' => '{}',
            'next_attempt_at' => '2026-08-20 09:00:00.000000',
        ]);
        $authorizationId = $db->table('supplier_import_dispatch_recovery_authorizations')->insertGetId([
            'supplier_import_execution_claim_id' => $claimId,
            'supplier_import_dispatch_outbox_id' => $outboxId,
            'logical_execution_key' => $logicalKey,
            'target_parent_type' => 'supplier_feed',
            'target_parent_id' => $parents['feed_id'],
            'authorization_action' => 'republish_same_key',
            'expected_state_fingerprint' => str_repeat('d', 64),
            'canonical_reason_code' => 'dispatch_durable_progress_stalled',
            'authorized_operator_id' => $userId,
            'authorized_at' => '2026-08-20 08:00:00.000000',
            'expires_at' => '2026-08-20 08:15:00.000000',
            'authorization_nonce_hash' => str_repeat('e', 64),
        ]);
        $resultId = $db->table('supplier_import_dispatch_recovery_results')->insertGetId([
            'supplier_import_dispatch_recovery_authorization_id' => $authorizationId,
            'authorization_action' => 'republish_same_key',
            'authorized_operator_id' => $userId,
            'supplier_import_execution_claim_id' => $claimId,
            'supplier_import_dispatch_outbox_id' => $outboxId,
            'logical_execution_key' => $logicalKey,
            'target_parent_type' => 'supplier_feed',
            'target_parent_id' => $parents['feed_id'],
            'event_sequence' => 1,
            'event_kind' => 'started',
            'canonical_result_code' => 'authorization_attempt_started',
            'resume_state_fingerprint' => str_repeat('f', 64),
            'occurred_at' => '2026-08-20 08:01:00.000000',
            'result_fingerprint' => str_repeat('1', 64),
        ]);
        $cohortMemberId = $db->table('supplier_import_cohort_authorization_members')->insertGetId([
            'supplier_import_execution_claim_id' => $claimId,
            'supplier_sku_hash' => str_repeat('2', 64),
        ]);
        $generationId = $db->table('supplier_offer_snapshot_generations')->insertGetId([
            'supplier_id' => $parents['supplier_id'],
            'supplier_key' => 'schema-supplier-v1',
            'supplier_feed_id' => $parents['feed_id'],
            'supplier_import_execution_claim_id' => $claimId,
            'import_history_id' => $parents['history_id'],
            'schema_version' => 'supplier-offer-snapshot-v1',
            'producer_version' => 'schema-test-v1',
            'qualification_policy_key' => 'qualification-v1',
            'capture_integrity_policy_key' => 'capture-integrity-v1',
            'policy_versions' => '{}',
            'source_identity' => 'snapshot-source-v1:synthetic:fixture-a',
            'source_fingerprint' => str_repeat('3', 64),
            'captured_at' => '2026-08-20T08:05:00+00:00',
            'capture_started_at' => '2026-08-20T08:00:00+00:00',
            'capture_completed_at' => '2026-08-20T08:05:00+00:00',
            'capture_outcome' => 'failed',
            'capture_failure_reason_code' => 'synthetic_failure',
            'qualification_state' => 'frozen',
            'qualification_reason_codes' => '["synthetic_failure"]',
            'minimum_product_count' => 1,
            'maximum_product_drop_percent' => 40,
            'generation_fingerprint' => str_repeat('4', 64),
        ]);
        $enrollmentId = $db->table('supplier_offer_snapshot_enrollments')->insertGetId([
            'supplier_id' => $parents['supplier_id'],
            'supplier_feed_id' => $parents['feed_id'],
            'source_identity' => 'snapshot-source-v1:synthetic:fixture-a',
            'supplier_sku_hash' => str_repeat('2', 64),
            'effective_import_history_id' => $parents['history_id'],
            'enrollment_source' => 'capture_start_seed',
            'enrollment_fingerprint' => str_repeat('5', 64),
            'enrolled_at' => '2026-08-20T08:05:00+00:00',
        ]);
        $observationId = $db->table('supplier_offer_snapshot_observations')->insertGetId([
            'snapshot_generation_id' => $generationId,
            'snapshot_enrollment_id' => $enrollmentId,
            'supplier_sku_hash' => str_repeat('2', 64),
            'present' => false,
            'observation_fingerprint' => str_repeat('6', 64),
        ]);

        return $parents + [
            'user_id' => $userId,
            'claim_id' => $claimId,
            'outbox_id' => $outboxId,
            'alert_id' => $alertId,
            'authorization_id' => $authorizationId,
            'result_id' => $resultId,
            'cohort_member_id' => $cohortMemberId,
            'generation_id' => $generationId,
            'enrollment_id' => $enrollmentId,
            'observation_id' => $observationId,
        ];
    }

    private function assertParentDeleteWinsRace(PDO $pdoA, PDO $pdoB): void
    {
        $fixture = $this->seedRaceJobFixture('parent-delete-wins');
        $claim = $this->raceClaimValues($fixture, 'parent-delete-wins');

        try {
            $pdoA->beginTransaction();
            $delete = $pdoA->prepare('DELETE FROM import_jobs WHERE id = ?');
            $delete->execute([$fixture['job_id']]);
            $this->assertSame(1, $delete->rowCount());

            $pdoB->beginTransaction();
            $this->assertPdoMysqlError(
                fn () => $this->insertRaceClaim($pdoB, $claim),
                1205,
                'HY000',
            );
            $pdoB->rollBack();
            $pdoA->commit();

            $this->assertPdoMysqlError(
                fn () => $this->insertRaceClaim($pdoB, $claim),
                1452,
                '23000',
            );
            $this->assertSame(0, DB::table('import_jobs')->where('id', $fixture['job_id'])->count());
            $this->assertSame(0, DB::table('supplier_import_execution_claims')
                ->where('logical_execution_key', $claim['logical_execution_key'])->count());
        } finally {
            $this->rollbackPdo($pdoA);
            $this->rollbackPdo($pdoB);
        }
    }

    private function assertChildInsertWinsRace(PDO $pdoA, PDO $pdoB): void
    {
        $fixture = $this->seedRaceJobFixture('child-insert-wins');
        $claim = $this->raceClaimValues($fixture, 'child-insert-wins');

        try {
            $pdoA->beginTransaction();
            $this->insertRaceClaim($pdoA, $claim);

            $pdoB->beginTransaction();
            $this->assertPdoMysqlError(
                function () use ($pdoB, $fixture): void {
                    $statement = $pdoB->prepare('DELETE FROM import_jobs WHERE id = ?');
                    $statement->execute([$fixture['job_id']]);
                },
                1205,
                'HY000',
            );
            $pdoB->rollBack();
            $pdoA->commit();

            $this->assertPdoMysqlError(
                function () use ($pdoB, $fixture): void {
                    $statement = $pdoB->prepare('DELETE FROM import_jobs WHERE id = ?');
                    $statement->execute([$fixture['job_id']]);
                },
                1451,
                '23000',
            );
            $this->assertSame(1, DB::table('import_jobs')->where('id', $fixture['job_id'])->count());
            $this->assertSame(1, DB::table('supplier_import_execution_claims')
                ->where('logical_execution_key', $claim['logical_execution_key'])->count());
        } finally {
            $this->rollbackPdo($pdoA);
            $this->rollbackPdo($pdoB);
        }
    }

    /** @return array{supplier_id: int, feed_id: int, job_id: int} */
    private function seedRaceJobFixture(string $suffix): array
    {
        $now = '2026-08-20 08:00:00';
        $supplierId = DB::table('suppliers')->insertGetId([
            'company_name' => 'Race Supplier '.$suffix,
            'slug' => 'race-supplier-'.$suffix.'-'.strtolower(bin2hex(random_bytes(3))),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $feedId = DB::table('supplier_feeds')->insertGetId([
            'supplier_id' => $supplierId,
            'feed_name' => 'Race Feed '.$suffix,
            'feed_url' => 'https://example.test/race-'.$suffix.'.xml',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $jobId = DB::table('import_jobs')->insertGetId([
            'supplier_id' => $supplierId,
            'supplier_feed_id' => $feedId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'supplier_id' => $supplierId,
            'feed_id' => $feedId,
            'job_id' => $jobId,
        ];
    }

    /**
     * @param  array{supplier_id: int, feed_id: int, job_id: int}  $fixture
     * @return array{logical_execution_key: string, supplier_id: int, feed_id: int, job_id: int}
     */
    private function raceClaimValues(array $fixture, string $suffix): array
    {
        return $fixture + [
            'logical_execution_key' => hash('sha256', 'canonical-schema-race-'.$suffix),
        ];
    }

    /** @param array{logical_execution_key: string, supplier_id: int, feed_id: int, job_id: int} $claim */
    private function insertRaceClaim(PDO $pdo, array $claim): void
    {
        $statement = $pdo->prepare(<<<'SQL'
            INSERT INTO supplier_import_execution_claims (
                logical_execution_key, supplier_id, supplier_feed_id,
                import_job_id, allocated_at, execution_path
            ) VALUES (?, ?, ?, ?, '2026-08-20 08:00:00.000000', 'legacy_xml')
            SQL);
        $statement->execute([
            $claim['logical_execution_key'],
            $claim['supplier_id'],
            $claim['feed_id'],
            $claim['job_id'],
        ]);
    }

    private function assertPdoMysqlError(callable $operation, int $driverCode, string $sqlState): void
    {
        try {
            $operation();
        } catch (PDOException $exception) {
            $this->assertSame($sqlState, (string) ($exception->errorInfo[0] ?? ''));
            $this->assertSame($driverCode, (int) ($exception->errorInfo[1] ?? 0));

            return;
        }

        $this->fail(sprintf('Expected MySQL error %d (%s).', $driverCode, $sqlState));
    }

    private function rollbackPdo(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    private function assertQueryRejected(callable $operation, string $messageFragment): void
    {
        try {
            $operation();
            $this->fail('Expected MySQL to reject the operation.');
        } catch (QueryException $exception) {
            $this->assertStringContainsStringIgnoringCase($messageFragment, $exception->getMessage());
        }
    }

    private function createTemporaryDatabase(string $database): void
    {
        DB::statement(sprintf(
            'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $database,
        ));
    }

    private function dropTemporaryDatabase(string $database): void
    {
        try {
            DB::statement(sprintf('DROP DATABASE IF EXISTS `%s`', $database));
        } catch (Throwable) {
            // Preserve the primary test failure when cleanup itself cannot connect.
        }
    }

    private function recreateTemporaryDatabase(string $database): void
    {
        DB::disconnect('snapshot_schema_phase_i');
        DB::purge('snapshot_schema_phase_i');
        $this->dropTemporaryDatabase($database);
        $this->createTemporaryDatabase($database);
        $this->configureTemporaryConnection($database);
        $this->resetDownGuard();
    }

    private function configureTemporaryConnection(string $database): void
    {
        config(['database.connections.snapshot_schema_phase_i' => array_merge(
            config('database.connections.mysql'),
            ['database' => $database],
        )]);
        DB::purge('snapshot_schema_phase_i');
    }

    private function copyHistoricalMigrations(string $path): void
    {
        File::deleteDirectory($path);
        File::ensureDirectoryExists($path, 0700);

        foreach (File::files(database_path('migrations')) as $migration) {
            if (str_starts_with($migration->getFilename(), '2026_08_20_12')) {
                continue;
            }
            File::copy($migration->getPathname(), $path.DIRECTORY_SEPARATOR.$migration->getFilename());
        }
    }

    private function migrateHistoricalThenPhase(string $historicalPath): void
    {
        $this->assertSame(0, Artisan::call('migrate', [
            '--database' => 'snapshot_schema_phase_i',
            '--path' => $historicalPath,
            '--realpath' => true,
            '--force' => true,
        ]), Artisan::output());
        $this->assertSame(0, Artisan::call('migrate', [
            '--database' => 'snapshot_schema_phase_i',
            '--path' => database_path('migrations'),
            '--realpath' => true,
            '--force' => true,
        ]), Artisan::output());
    }

    private function assertGuardRejectedContaining(string $fragment): void
    {
        $this->assertStringContainsString($fragment, $this->guardRejectionMessage());
    }

    /** @param array<string, mixed> $parameters */
    private function guardRejectionMessage(array $parameters = []): string
    {
        return $this->commandRejectionMessage('migrate:rollback', $parameters);
    }

    /** @param array<string, mixed> $parameters */
    private function commandRejectionMessage(string $command, array $parameters = []): string
    {
        try {
            $exitCode = Artisan::call($command, array_merge([
                '--database' => 'snapshot_schema_phase_i',
                '--force' => true,
            ], $parameters));
        } catch (Throwable $exception) {
            return $exception->getMessage();
        }

        $this->fail(sprintf(
            'Expected destructive down guard rejection from %s, got exit code %d: %s',
            $command,
            $exitCode,
            Artisan::output(),
        ));
    }

    private function assertPhaseISchemaUntouched(int $expectedMigrationRows, string $context): void
    {
        $this->assertTrue(
            $this->indexExists('import_histories', 'ix_import_history_supplier_id'),
            $context.': first reverse-owned support index was removed',
        );
        $this->assertTrue(
            $this->indexExists('import_jobs', 'uq_import_job_id_supplier_feed'),
            $context.': Phase I ownership support index was removed',
        );
        $this->assertSame(
            10,
            collect(self::CANONICAL_TABLES)->filter(
                static fn (string $table): bool => Schema::hasTable($table),
            )->count(),
            $context.': canonical table inventory changed',
        );
        $this->assertSame(
            $expectedMigrationRows,
            DB::table('migrations')->whereIn('migration', self::PHASE_I_MIGRATIONS)->count(),
            $context.': Phase I migration repository state changed',
        );
    }

    private function rollbackArrayInput(): ArrayInput
    {
        return new ArrayInput([
            'command' => 'migrate:rollback',
            '--database' => 'snapshot_schema_phase_i',
            '--force' => true,
        ], new InputDefinition([
            new InputArgument('command', InputArgument::REQUIRED),
            new InputOption('database', null, InputOption::VALUE_OPTIONAL),
            new InputOption('force', null, InputOption::VALUE_NONE),
            new InputOption('step', null, InputOption::VALUE_OPTIONAL),
            new InputOption('batch', null, InputOption::VALUE_REQUIRED),
            new InputOption('path', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY),
            new InputOption('realpath', null, InputOption::VALUE_NONE),
            new InputOption('pretend', null, InputOption::VALUE_NONE),
        ]));
    }

    private function resetDownGuard(): void
    {
        $reflection = new ReflectionClass(CanonicalSupplierSnapshotSchema::class);
        $property = $reflection->getProperty('destructiveDownScope');
        $property->setValue(null, null);
    }

    private function issueDownCapability(): string
    {
        $capability = CanonicalSupplierSnapshotSchema::issueDestructiveDownCapability();
        $this->issuedDownCapabilities[] = $capability;
        $this->presentDownCapability($capability);

        return $capability;
    }

    private function presentDownCapability(string $capability): void
    {
        putenv(self::DOWN_CAPABILITY_ENV.'='.$capability);
        $_ENV[self::DOWN_CAPABILITY_ENV] = $capability;
        $_SERVER[self::DOWN_CAPABILITY_ENV] = $capability;
    }

    /** @return array{exit: int, message: string} */
    private function rollbackAttempt(string $capability): array
    {
        $this->presentDownCapability($capability);
        try {
            $exit = Artisan::call('migrate:rollback', [
                '--database' => 'snapshot_schema_phase_i',
                '--force' => true,
            ]);

            return ['exit' => $exit, 'message' => Artisan::output()];
        } catch (Throwable $exception) {
            return ['exit' => 1, 'message' => $exception->getMessage()];
        }
    }

    private function recreateArtifactFixture(string $token, string $payload): void
    {
        $directory = $this->capabilityDirectory($token);
        $path = $this->capabilityPath($token);
        clearstatcache(true, $directory);
        if (! is_dir($directory)) {
            $this->createIndependentPrivateDirectory($directory);
        }

        $previousUmask = null;
        if (PHP_OS_FAMILY !== 'Windows') {
            $previousUmask = umask(0077);
        }
        try {
            $handle = fopen($path, 'x+b');
        } finally {
            if ($previousUmask !== null) {
                umask($previousUmask);
            }
        }
        $this->assertIsResource($handle);
        try {
            $this->assertSame(strlen($payload), fwrite($handle, $payload));
            $this->assertTrue(fflush($handle));
        } finally {
            fclose($handle);
        }

        $this->assertCapabilityPathSecurity($path, $directory);
    }

    private function createIndependentPrivateDirectory(string $path): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $previousUmask = umask(0077);
            try {
                $this->assertTrue(mkdir($path, 0700));
            } finally {
                umask($previousUmask);
            }

            return;
        }

        $systemRoot = getenv('SystemRoot');
        $this->assertIsString($systemRoot);
        $powerShell = $systemRoot.'\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        $script = <<<'POWERSHELL'
            $ErrorActionPreference = 'Stop'
            $path = [IO.Path]::GetFullPath([string] $env:MYCOMPUTER_PHASE_I_TEST_PATH)
            $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
            $security = New-Object Security.AccessControl.DirectorySecurity
            $security.SetOwner($identity.User)
            $security.SetAccessRuleProtection($true, $false)
            $rule = New-Object Security.AccessControl.FileSystemAccessRule(
                $identity.User,
                [Security.AccessControl.FileSystemRights]::FullControl,
                [Security.AccessControl.InheritanceFlags]'ContainerInherit, ObjectInherit',
                [Security.AccessControl.PropagationFlags]::None,
                [Security.AccessControl.AccessControlType]::Allow
            )
            [void] $security.AddAccessRule($rule)
            [void] [IO.Directory]::CreateDirectory($path, $security)
            POWERSHELL;
        $process = new Process([
            $powerShell,
            '-NoLogo',
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy',
            'Bypass',
            '-Command',
            $script,
        ], env: ['MYCOMPUTER_PHASE_I_TEST_PATH' => $path]);
        $process->setTimeout(10);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'Independent private-directory fixture creation failed.');
        $this->assertIndependentWindowsAclSecure($path, true);
    }

    private function capabilityDirectory(string $token): string
    {
        return (string) $this->invokeCapabilityMethod('capabilityDirectory', [$token]);
    }

    private function capabilityPath(string $token): string
    {
        return (string) $this->invokeCapabilityMethod('capabilityPath', [$token]);
    }

    private function capabilityLedgerRoot(): string
    {
        return (string) $this->invokeCapabilityMethod('capabilityLedgerRootPath');
    }

    private function capabilityIssuedLedgerPath(string $token): string
    {
        return (string) $this->invokeCapabilityMethod('capabilityIssuedLedgerPathForToken', [$token]);
    }

    private function capabilitySpentLedgerPath(string $token): string
    {
        return (string) $this->invokeCapabilityMethod('capabilitySpentLedgerPathForToken', [$token]);
    }

    private function assertCapabilityPathSecurity(string $path, string $directory): void
    {
        $this->assertTrue(is_dir($directory));
        $this->assertTrue(is_file($path));
        $this->assertFalse(is_link($directory));
        $this->assertFalse(is_link($path));

        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertIndependentWindowsAclSecure($directory, true);
            $this->assertIndependentWindowsAclSecure($path, false);

            return;
        }

        $directoryStat = lstat($directory);
        $artifactStat = lstat($path);
        $this->assertIsArray($directoryStat);
        $this->assertIsArray($artifactStat);
        $this->assertSame(0040000, $directoryStat['mode'] & 0170000);
        $this->assertSame(0100000, $artifactStat['mode'] & 0170000);
        $this->assertSame(0700, $directoryStat['mode'] & 0777);
        $this->assertSame(0600, $artifactStat['mode'] & 0777);
        $this->assertSame(posix_geteuid(), $directoryStat['uid']);
        $this->assertSame(posix_geteuid(), $artifactStat['uid']);
        $this->assertSame(1, $artifactStat['nlink']);
    }

    private function assertCapabilityConsumptionRejectedAndCleaned(
        string $token,
        string $fragment,
        string $context,
    ): void {
        try {
            $this->invokeCapabilityMethod('consumeInvocationCapability');
            $this->fail($context.': capability consumption unexpectedly succeeded');
        } catch (Throwable $exception) {
            $this->assertStringContainsString($fragment, $exception->getMessage(), $context);
        }

        $this->assertCapabilitySpentAndArtifactAbsent($token, $context);
    }

    private function assertCapabilitySpentAndArtifactAbsent(string $token, string $context): void
    {
        $path = $this->capabilityPath($token);
        $directory = $this->capabilityDirectory($token);
        $issuedPath = $this->capabilityIssuedLedgerPath($token);
        $spentPath = $this->capabilitySpentLedgerPath($token);
        clearstatcache(true, $path);
        clearstatcache(true, $directory);
        clearstatcache(true, $issuedPath);
        clearstatcache(true, $spentPath);
        $consumed = glob($directory.DIRECTORY_SEPARATOR.'consumed-*.json');

        $this->assertFalse(file_exists($path) || is_link($path), $context.': original artifact remains');
        $this->assertSame([], is_array($consumed) ? $consumed : [], $context.': consumed artifact remains');
        $this->assertFalse(
            file_exists($directory) || is_link($directory),
            $context.': private capability directory remains',
        );
        $this->assertTrue(is_file($issuedPath), $context.': authoritative issued record is missing');
        $this->assertTrue(is_file($spentPath), $context.': spent/revoked record is missing');
        $this->assertCapabilityPathSecurity($issuedPath, $this->capabilityLedgerRoot());
        $this->assertCapabilityPathSecurity($spentPath, $this->capabilityLedgerRoot());

        $reflection = new ReflectionClass(CanonicalSupplierSnapshotSchema::class);
        $this->assertNull(
            $reflection->getProperty('destructiveDownScope')->getValue(),
            $context.': destructive down scope remains active',
        );
    }

    private function assertUnissuedCapabilityStateAbsent(string $token, string $context): void
    {
        $path = $this->capabilityPath($token);
        $directory = $this->capabilityDirectory($token);
        $issuedPath = $this->capabilityIssuedLedgerPath($token);
        $spentPath = $this->capabilitySpentLedgerPath($token);
        foreach ([$path, $directory, $issuedPath, $spentPath] as $candidate) {
            clearstatcache(true, $candidate);
            $this->assertFalse(
                file_exists($candidate) || is_link($candidate),
                $context.': unissued capability state exists',
            );
        }
    }

    private function purgeCapabilityLedgerForTesting(string $token): void
    {
        foreach ([$this->capabilitySpentLedgerPath($token), $this->capabilityIssuedLedgerPath($token)] as $path) {
            clearstatcache(true, $path);
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
        }
    }

    private function removeEmptyCapabilityRootsForTesting(): void
    {
        foreach ([$this->capabilityLedgerRoot(), dirname($this->capabilityDirectory(str_repeat('0', 64)))] as $root) {
            clearstatcache(true, $root);
            if (! is_dir($root) || is_link($root)) {
                continue;
            }
            $entries = scandir($root);
            if (is_array($entries) && array_values(array_diff($entries, ['.', '..'])) === []) {
                @rmdir($root);
            }
        }
    }

    private function assertIndependentWindowsAclSecure(string $path, bool $directory): void
    {
        $acl = $this->observeWindowsAcl($path);
        $rules = $acl['rules'];

        $this->assertSame($acl['current_sid'], $acl['owner_sid']);
        $this->assertSame($directory, $acl['is_directory']);
        $this->assertSame(! $directory, $acl['is_file']);
        $this->assertFalse($acl['reparse']);
        $this->assertSame($directory, $acl['protected']);
        $this->assertCount(1, $rules);
        $this->assertSame($acl['current_sid'], $rules[0]['sid']);
        $this->assertSame('Allow', $rules[0]['type']);
        $this->assertSame(2032127, $rules[0]['rights_value']);
        $this->assertSame(! $directory, $rules[0]['inherited']);
        $this->assertSame($directory ? 'ContainerInherit, ObjectInherit' : 'None', $rules[0]['inheritance']);
        $this->assertSame('None', $rules[0]['propagation']);
    }

    private function assertIndependentWindowsAclUnsafe(string $path, bool $directory): void
    {
        $acl = $this->observeWindowsAcl($path);
        $this->assertSame($directory, $acl['is_directory']);
        $this->assertFalse($acl['reparse']);

        $unsafe = collect($acl['rules'])->filter(static fn (array $rule): bool => $rule['sid'] !== $acl['current_sid']
            && $rule['type'] === 'Allow'
            && ($rule['rights_value'] & self::WINDOWS_WRITE_RIGHTS_MASK) !== 0
        );
        $this->assertNotEmpty($unsafe, 'Independent ACL observer must detect an unprivileged write-capable ACE.');
        $this->assertContains('S-1-1-0', $unsafe->pluck('sid')->all());
    }

    /**
     * @return array{
     *     current_sid: string,
     *     owner_sid: string,
     *     protected: bool,
     *     reparse: bool,
     *     is_directory: bool,
     *     is_file: bool,
     *     rules: list<array{sid: string, type: string, rights_value: int, inherited: bool, inheritance: string, propagation: string}>
     * }
     */
    private function observeWindowsAcl(string $path): array
    {
        $systemRoot = getenv('SystemRoot');
        $this->assertIsString($systemRoot);
        $powerShell = $systemRoot.'\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        $script = <<<'POWERSHELL'
            $ErrorActionPreference = 'Stop'
            $path = [IO.Path]::GetFullPath([string] $env:MYCOMPUTER_PHASE_I_TEST_PATH)
            $isDirectory = [IO.Directory]::Exists($path)
            $isFile = [IO.File]::Exists($path)
            if (-not $isDirectory -and -not $isFile) {
                throw 'Independent ACL observation path is missing'
            }
            $acl = $(if ($isDirectory) {
                [IO.Directory]::GetAccessControl($path)
            } else {
                [IO.File]::GetAccessControl($path)
            })
            $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
            $rules = @($acl.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier]) | ForEach-Object {
                [ordered]@{
                    sid = $_.IdentityReference.Value
                    type = $_.AccessControlType.ToString()
                    rights_value = [int64] $_.FileSystemRights
                    inherited = [bool] $_.IsInherited
                    inheritance = $_.InheritanceFlags.ToString()
                    propagation = $_.PropagationFlags.ToString()
                }
            })
            [ordered]@{
                current_sid = $identity.User.Value
                owner_sid = $acl.GetOwner([Security.Principal.SecurityIdentifier]).Value
                protected = [bool] $acl.AreAccessRulesProtected
                reparse = [bool] (([IO.File]::GetAttributes($path) -band [IO.FileAttributes]::ReparsePoint) -ne 0)
                is_directory = [bool] $isDirectory
                is_file = [bool] $isFile
                rules = $rules
            } | ConvertTo-Json -Compress -Depth 5
            POWERSHELL;
        $process = new Process([
            $powerShell,
            '-NoLogo',
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy',
            'Bypass',
            '-Command',
            $script,
        ], env: ['MYCOMPUTER_PHASE_I_TEST_PATH' => $path]);
        $process->setTimeout(10);
        $process->run();
        $this->assertTrue(
            $process->isSuccessful(),
            'Independent Windows ACL observation failed: '.$process->getErrorOutput(),
        );

        $observed = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($observed);
        $this->assertSame(
            ['current_sid', 'owner_sid', 'protected', 'reparse', 'is_directory', 'is_file', 'rules'],
            array_keys($observed),
        );
        $this->assertIsArray($observed['rules']);

        return $observed;
    }

    /** @param list<mixed> $arguments */
    private function invokeCapabilityMethod(string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionClass(CanonicalSupplierSnapshotSchema::class);

        return $reflection->getMethod($method)->invoke(null, ...$arguments);
    }

    private function makeCapabilityPathUnsafe(string $path, bool $directory): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($path, $directory ? 0770 : 0660);

            return;
        }

        $systemRoot = getenv('SystemRoot');
        $this->assertIsString($systemRoot);
        $powerShell = $systemRoot.'\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        $script = <<<'POWERSHELL'
            $ErrorActionPreference = 'Stop'
            $path = [IO.Path]::GetFullPath([string] $env:MYCOMPUTER_PHASE_I_TEST_PATH)
            $directory = $env:MYCOMPUTER_PHASE_I_TEST_DIRECTORY -eq '1'
            $acl = $(if ($directory) { [IO.Directory]::GetAccessControl($path) } else { [IO.File]::GetAccessControl($path) })
            $world = New-Object Security.Principal.SecurityIdentifier(
                [Security.Principal.WellKnownSidType]::WorldSid,
                $null
            )
            $inheritance = $(if ($directory) {
                [Security.AccessControl.InheritanceFlags]'ContainerInherit, ObjectInherit'
            } else {
                [Security.AccessControl.InheritanceFlags]::None
            })
            $rule = New-Object Security.AccessControl.FileSystemAccessRule(
                $world,
                [Security.AccessControl.FileSystemRights]::Modify,
                $inheritance,
                [Security.AccessControl.PropagationFlags]::None,
                [Security.AccessControl.AccessControlType]::Allow
            )
            [void] $acl.AddAccessRule($rule)
            if ($directory) { [IO.Directory]::SetAccessControl($path, $acl) } else { [IO.File]::SetAccessControl($path, $acl) }
            POWERSHELL;
        $process = new Process([
            $powerShell,
            '-NoLogo',
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy',
            'Bypass',
            '-Command',
            $script,
        ], env: [
            'MYCOMPUTER_PHASE_I_TEST_PATH' => $path,
            'MYCOMPUTER_PHASE_I_TEST_DIRECTORY' => $directory ? '1' : '0',
        ]);
        $process->setTimeout(10);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'Test ACL mutation must succeed.');
    }

    private function restoreIndependentPathSecurity(string $path, bool $directory): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertTrue(chmod($path, $directory ? 0700 : 0600));

            return;
        }

        $systemRoot = getenv('SystemRoot');
        $this->assertIsString($systemRoot);
        $powerShell = $systemRoot.'\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        $script = <<<'POWERSHELL'
            $ErrorActionPreference = 'Stop'
            $path = [IO.Path]::GetFullPath([string] $env:MYCOMPUTER_PHASE_I_TEST_PATH)
            $directory = $env:MYCOMPUTER_PHASE_I_TEST_DIRECTORY -eq '1'
            $acl = $(if ($directory) { [IO.Directory]::GetAccessControl($path) } else { [IO.File]::GetAccessControl($path) })
            @($acl.GetAccessRules($true, $false, [Security.Principal.SecurityIdentifier])) | ForEach-Object {
                [void] $acl.RemoveAccessRuleSpecific($_)
            }
            $acl.SetAccessRuleProtection($false, $false)
            if ($directory) { [IO.Directory]::SetAccessControl($path, $acl) } else { [IO.File]::SetAccessControl($path, $acl) }
            POWERSHELL;
        $process = new Process([
            $powerShell,
            '-NoLogo',
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy',
            'Bypass',
            '-Command',
            $script,
        ], env: [
            'MYCOMPUTER_PHASE_I_TEST_PATH' => $path,
            'MYCOMPUTER_PHASE_I_TEST_DIRECTORY' => $directory ? '1' : '0',
        ]);
        $process->setTimeout(10);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'Independent ACL restoration must succeed.');
        $this->assertIndependentWindowsAclSecure($path, $directory);
    }

    private function clearDownCapabilityEnvironment(): void
    {
        putenv(self::DOWN_CAPABILITY_ENV);
        unset($_ENV[self::DOWN_CAPABILITY_ENV], $_SERVER[self::DOWN_CAPABILITY_ENV]);
    }

    private static function normalizeSql(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }

    /** @return array<string, string> */
    private function canonicalCreateStatements(): array
    {
        $statements = [];
        foreach (self::CANONICAL_TABLES as $table) {
            $row = (array) DB::selectOne(sprintf('SHOW CREATE TABLE `%s`', $table));
            $statements[$table] = (string) array_values($row)[1];
        }

        return $statements;
    }

    private function indexExists(string $table, string $index): bool
    {
        return (int) DB::scalar(<<<'SQL'
            SELECT COUNT(*)
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
            SQL, [$table, $index]) > 0;
    }
}
