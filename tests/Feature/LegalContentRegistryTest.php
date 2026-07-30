<?php

namespace Tests\Feature;

use App\Services\Legal\LegalContentRegistry;
use Tests\TestCase;

class LegalContentRegistryTest extends TestCase
{
    /**
     * @var array<int, string>
     */
    private array $temporaryManifests = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryManifests as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_draft_manifest_is_valid_but_never_approved(): void
    {
        config()->set('legal.approved', true);
        config()->set(
            'legal.manifest_path',
            base_path('frontend/app/data/legal/legal-content-manifest.json'),
        );

        $registry = app(LegalContentRegistry::class);

        $this->assertTrue($registry->isManifestValid());
        $this->assertTrue($registry->isDraft());
        $this->assertFalse($registry->isApproved());
        $this->assertFalse($registry->effectiveDatesPresent());
        $this->assertSame('/obshti-usloviya', $registry->termsRoute());
        $this->assertSame('/politika-za-poveritelnost', $registry->privacyRoute());
    }

    public function test_approval_requires_both_flag_and_complete_approved_manifest(): void
    {
        $manifest = $this->approvedManifest();
        config()->set('legal.manifest_path', $this->writeManifest($manifest));
        config()->set('legal.approved', false);

        $this->assertFalse(app(LegalContentRegistry::class)->isApproved());

        config()->set('legal.approved', true);
        $registry = app(LegalContentRegistry::class);

        $this->assertTrue($registry->isManifestValid());
        $this->assertTrue($registry->effectiveDatesPresent());
        $this->assertTrue($registry->isApproved());
        $this->assertSame('terms-final-1', $registry->termsVersion());
        $this->assertSame('privacy-final-1', $registry->privacyVersion());
    }

    public function test_missing_versions_dates_and_invalid_routes_fail_closed(): void
    {
        foreach ([
            ['terms.version' => ''],
            ['privacy.effective_date' => null],
            ['terms.route' => '/terms'],
            ['privacy.route' => '/privacy'],
        ] as $mutation) {
            $manifest = $this->approvedManifest();

            foreach ($mutation as $path => $value) {
                data_set($manifest, $path, $value);
            }

            config()->set('legal.approved', true);
            config()->set('legal.manifest_path', $this->writeManifest($manifest));

            $this->assertFalse(
                app(LegalContentRegistry::class)->isApproved(),
                'Manifest mutation should fail closed: '.json_encode($mutation),
            );
        }
    }

    public function test_missing_or_malformed_manifest_fails_closed_without_exposing_details(): void
    {
        config()->set('legal.approved', true);
        config()->set('legal.manifest_path', $this->writeManifest('{invalid json'));

        $registry = app(LegalContentRegistry::class);

        $this->assertSame([], $registry->manifest());
        $this->assertFalse($registry->isManifestValid());
        $this->assertFalse($registry->isApproved());
        $this->assertSame('', $registry->termsVersion());
        $this->assertSame('', $registry->privacyVersion());
    }

    /**
     * @return array<string, mixed>
     */
    private function approvedManifest(): array
    {
        return [
            'locale' => 'bg',
            'status' => 'approved',
            'terms' => [
                'route' => '/obshti-usloviya',
                'version' => 'terms-final-1',
                'effective_date' => '2026-07-29',
            ],
            'privacy' => [
                'route' => '/politika-za-poveritelnost',
                'version' => 'privacy-final-1',
                'effective_date' => '2026-07-29',
            ],
        ];
    }

    private function writeManifest(array|string $manifest): string
    {
        $path = tempnam(sys_get_temp_dir(), 'legal-manifest-');
        $this->assertNotFalse($path);
        file_put_contents(
            $path,
            is_array($manifest)
                ? json_encode($manifest, JSON_THROW_ON_ERROR)
                : $manifest,
        );
        $this->temporaryManifests[] = $path;

        return $path;
    }
}
