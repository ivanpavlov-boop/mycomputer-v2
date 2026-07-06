<?php

namespace App\Console\Commands;

use App\Models\CanonicalProductFamily;
use App\Services\Taxonomy\CanonicalProductFamilyCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedCanonicalProductFamilies extends Command
{
    protected $signature = 'taxonomy:seed-canonical-families
        {--apply : Persist canonical product families}
        {--dry-run : Preview changes without writing anything}';

    protected $description = 'Preview or apply the controlled canonical product family seed list.';

    public function handle(): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Use either --apply or --dry-run, not both.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $stats = $apply
            ? DB::transaction(fn (): array => $this->process(true))
            : $this->process(false);

        $this->info($apply ? 'Canonical product families applied.' : 'Dry-run only. No records were changed.');
        $this->line(($apply ? 'Families created: ' : 'Families to create: ').$stats['families_to_create']);
        $this->line(($apply ? 'Families updated: ' : 'Families to update: ').$stats['families_to_update']);
        $this->line('Families already present: '.$stats['families_existing']);

        if ($stats['created_codes'] !== []) {
            $this->line('Create: '.implode(', ', $stats['created_codes']));
        }

        if ($stats['updated_codes'] !== []) {
            $this->line('Update: '.implode(', ', $stats['updated_codes']));
        }

        $this->zeroChangeCounters();

        return self::SUCCESS;
    }

    /**
     * @return array{families_to_create: int, families_to_update: int, families_existing: int, created_codes: array<int, string>, updated_codes: array<int, string>}
     */
    private function process(bool $apply): array
    {
        $stats = [
            'families_to_create' => 0,
            'families_to_update' => 0,
            'families_existing' => 0,
            'created_codes' => [],
            'updated_codes' => [],
        ];

        foreach (app(CanonicalProductFamilyCatalog::class)->families() as $definition) {
            $family = CanonicalProductFamily::withTrashed()
                ->where('code', $definition['code'])
                ->first();

            if (! $family) {
                $stats['families_to_create']++;
                $stats['created_codes'][] = $definition['code'];

                if ($apply) {
                    CanonicalProductFamily::query()->create($definition);
                }

                continue;
            }

            $updates = $this->updatesFor($family, $definition);

            if ($updates === []) {
                $stats['families_existing']++;

                continue;
            }

            $stats['families_to_update']++;
            $stats['updated_codes'][] = $definition['code'];

            if ($apply && ! $family->trashed()) {
                $family->fill($updates)->save();
            }
        }

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function updatesFor(CanonicalProductFamily $family, array $definition): array
    {
        if ($family->trashed()) {
            return [];
        }

        $updates = [];

        foreach (['name_bg', 'name_en', 'description_bg', 'description_en', 'sort_order', 'active', 'metadata'] as $field) {
            if ($family->{$field} != $definition[$field]) {
                $updates[$field] = $definition[$field];
            }
        }

        return $updates;
    }

    private function zeroChangeCounters(): void
    {
        $this->line('products changed: 0');
        $this->line('supplier_products changed: 0');
        $this->line('categories changed: 0');
        $this->line('category_product_attributes changed: 0');
        $this->line('product_attributes changed: 0');
        $this->line('attribute_values changed: 0');
        $this->line('product_attribute_values changed: 0');
    }
}
