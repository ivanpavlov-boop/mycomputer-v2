<?php

namespace Tests\Feature;

use App\Services\Legal\LegalContentRegistry;
use Tests\TestCase;

class LegalContentRegistryTest extends TestCase
{
    /**
     * @var array<int, string>
     */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_committed_approved_manifest_is_valid_but_runtime_approval_remains_explicit(): void
    {
        $this->useCommittedLegalContent();
        config()->set('legal.approved', false);

        $registry = app(LegalContentRegistry::class);

        $this->assertTrue($registry->isManifestValid());
        $this->assertFalse($registry->isDraft());
        $this->assertTrue($registry->effectiveDatesPresent());
        $this->assertFalse($registry->isApproved());
        $this->assertSame('/obshti-usloviya', $registry->termsRoute());
        $this->assertSame('/politika-za-poveritelnost', $registry->privacyRoute());
        $this->assertSame('bg-terms-v1.0-2026-07-30', $registry->termsVersion());
        $this->assertSame('bg-privacy-v1.0-2026-07-30', $registry->privacyVersion());

        config()->set('legal.approved', true);

        $this->assertTrue(app(LegalContentRegistry::class)->isApproved());
    }

    public function test_draft_manifest_is_structurally_valid_but_never_approved(): void
    {
        $manifest = [
            'locale' => 'bg',
            'status' => 'draft',
            'terms' => [
                'route' => '/obshti-usloviya',
                'version' => 'draft-test',
                'effective_date' => null,
            ],
            'privacy' => [
                'route' => '/politika-za-poveritelnost',
                'version' => 'draft-test',
                'effective_date' => null,
            ],
        ];

        config()->set('legal.approved', true);
        config()->set('legal.manifest_path', $this->writeJson($manifest));

        $registry = app(LegalContentRegistry::class);

        $this->assertTrue($registry->isManifestValid());
        $this->assertTrue($registry->isDraft());
        $this->assertFalse($registry->effectiveDatesPresent());
        $this->assertFalse($registry->isApproved());
    }

    public function test_approved_manifest_dates_hashes_and_metadata_fail_closed_when_invalid(): void
    {
        foreach ([
            ['terms.effective_date' => null],
            ['terms.effective_date' => '2026-02-30'],
            ['privacy.effective_date' => '2026-07-30T00:00:00Z'],
            ['terms.source_sha256' => strtoupper(str_repeat('a', 64))],
            ['privacy.source_sha256' => 'not-a-hash'],
            ['approval' => null],
            ['approval.approved_by_role' => 'super_admin'],
            ['approval.legal_counsel_review' => 'approved'],
            ['approval.approved_at' => '2026-02-30'],
            ['terms.route' => '/terms'],
            ['privacy.route' => '/privacy'],
            ['terms.version' => ''],
        ] as $mutation) {
            [$manifest, $record] = $this->approvedFixture();

            foreach ($mutation as $path => $value) {
                data_set($manifest, $path, $value);
            }

            $this->configureFixture($manifest, $record);
            config()->set('legal.approved', true);

            $this->assertFalse(
                app(LegalContentRegistry::class)->isApproved(),
                'Manifest mutation should fail closed: '.json_encode($mutation),
            );
        }
    }

    public function test_manifest_version_or_date_change_without_matching_approval_record_fails_closed(): void
    {
        foreach ([
            ['terms.version' => 'bg-terms-v1.1-2026-07-30'],
            ['privacy.effective_date' => '2026-07-31'],
        ] as $mutation) {
            [$manifest, $record] = $this->approvedFixture();

            foreach ($mutation as $path => $value) {
                data_set($manifest, $path, $value);
            }

            $this->configureFixture($manifest, $record);

            $this->assertFalse(app(LegalContentRegistry::class)->isManifestValid());
        }
    }

    public function test_missing_source_and_changed_source_fail_closed(): void
    {
        [$manifest, $record, $termsPath] = $this->approvedFixture();
        $this->configureFixture($manifest, $record);

        file_put_contents($termsPath, "\nchanged", FILE_APPEND);

        $this->assertFalse(app(LegalContentRegistry::class)->isManifestValid());

        [$manifest, $record] = $this->approvedFixture();
        $this->configureFixture($manifest, $record);
        config()->set('legal.source_pages.terms', $termsPath.'.missing');

        $this->assertFalse(app(LegalContentRegistry::class)->isManifestValid());
    }

    public function test_missing_or_mismatched_approval_record_fails_closed(): void
    {
        [$manifest, $record] = $this->approvedFixture();
        $record['privacy_source_sha256'] = str_repeat('0', 64);
        $this->configureFixture($manifest, $record);

        $this->assertFalse(app(LegalContentRegistry::class)->isManifestValid());

        [$manifest] = $this->approvedFixture();
        config()->set('legal.manifest_path', $this->writeJson($manifest));
        config()->set('legal.approval_record_path', $this->writeText('{invalid'));

        $this->assertFalse(app(LegalContentRegistry::class)->isManifestValid());
    }

    public function test_missing_or_malformed_manifest_fails_closed_without_exposing_details(): void
    {
        config()->set('legal.approved', true);
        config()->set('legal.manifest_path', $this->writeText('{invalid json'));

        $registry = app(LegalContentRegistry::class);

        $this->assertSame([], $registry->manifest());
        $this->assertFalse($registry->isManifestValid());
        $this->assertFalse($registry->isApproved());
        $this->assertSame('', $registry->termsVersion());
        $this->assertSame('', $registry->privacyVersion());
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string, 3: string}
     */
    private function approvedFixture(): array
    {
        $termsPath = $this->writeText('Approved Terms source');
        $privacyPath = $this->writeText('Approved Privacy source');
        $termsHash = hash_file('sha256', $termsPath);
        $privacyHash = hash_file('sha256', $privacyPath);
        $manifest = [
            'locale' => 'bg',
            'status' => 'approved',
            'terms' => [
                'route' => '/obshti-usloviya',
                'version' => 'bg-terms-v1.0-2026-07-30',
                'effective_date' => '2026-07-30',
                'source_sha256' => $termsHash,
            ],
            'privacy' => [
                'route' => '/politika-za-poveritelnost',
                'version' => 'bg-privacy-v1.0-2026-07-30',
                'effective_date' => '2026-07-30',
                'source_sha256' => $privacyHash,
            ],
            'approval' => [
                'approved_by_role' => 'project_owner',
                'approved_at' => '2026-07-30',
                'legal_counsel_review' => 'not_claimed',
            ],
        ];
        $record = [
            'phase' => 'Legal Content Finalization and Explicit Approval',
            'locale' => 'bg',
            'status' => 'approved',
            'approved_by_role' => 'project_owner',
            'approved_at' => '2026-07-30',
            'legal_counsel_review' => 'not_claimed',
            'terms_version' => $manifest['terms']['version'],
            'terms_effective_date' => $manifest['terms']['effective_date'],
            'terms_source_sha256' => $termsHash,
            'privacy_version' => $manifest['privacy']['version'],
            'privacy_effective_date' => $manifest['privacy']['effective_date'],
            'privacy_source_sha256' => $privacyHash,
            'base_commit' => 'df7d59c387d3930560bc49e3fead0e5622881dbe',
            'notes' => 'Test-only approval evidence.',
        ];

        config()->set('legal.source_pages', [
            'terms' => $termsPath,
            'privacy' => $privacyPath,
        ]);

        return [$manifest, $record, $termsPath, $privacyPath];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $record
     */
    private function configureFixture(array $manifest, array $record): void
    {
        config()->set('legal.manifest_path', $this->writeJson($manifest));
        config()->set('legal.approval_record_path', $this->writeJson($record));
    }

    private function useCommittedLegalContent(): void
    {
        config()->set(
            'legal.manifest_path',
            base_path('frontend/app/data/legal/legal-content-manifest.json'),
        );
        config()->set(
            'legal.approval_record_path',
            base_path('docs/legal/LEGAL_CONTENT_APPROVAL_2026-07-30.json'),
        );
        config()->set('legal.source_pages', [
            'terms' => base_path('frontend/app/data/legal/terms.bg.ts'),
            'privacy' => base_path('frontend/app/data/legal/privacy.bg.ts'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(array $payload): string
    {
        return $this->writeText(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function writeText(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'legal-content-');
        $this->assertNotFalse($path);
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
