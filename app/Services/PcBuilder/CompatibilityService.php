<?php

namespace App\Services\PcBuilder;

use App\Models\PcBuild;
use App\Models\PcCompatibilityRule;
use App\Models\Product;

class CompatibilityService
{
    public function validate(PcBuild $build): array
    {
        $build->loadMissing(['items.product.attributeValues.attribute', 'items.product.attributeValues.value', 'items.product.attributeValues.canonicalAttribute', 'items.product.attributeValues.canonicalAttributeValue']);
        $warnings = [];
        $errors = [];
        $recommendations = [];

        $this->pair($build, 'cpu', 'motherboard', 'socket', 'socket', 'CPU socket matches motherboard', $recommendations, $warnings, $errors);
        $this->pair($build, 'ram', 'motherboard', 'memory_type', 'memory_type', 'RAM type matches motherboard', $recommendations, $warnings, $errors);
        $this->pair($build, 'case', 'motherboard', 'form_factor', 'form_factor', 'Case form factor supports motherboard', $recommendations, $warnings, $errors, allowContains: true);
        $this->pair($build, 'cooler', 'cpu', 'socket', 'socket', 'Cooler supports CPU socket', $recommendations, $warnings, $errors, allowContains: true);
        $this->pair($build, 'storage', 'motherboard', 'storage_interface', 'storage_interface', 'Storage interface is supported', $recommendations, $warnings, $errors, allowContains: true);
        $this->power($build, $recommendations, $warnings);

        foreach (PcCompatibilityRule::query()->where('is_active', true)->orderByDesc('priority')->get() as $rule) {
            $recommendations[] = 'Rule '.$rule->rule_type.' is active: '.$rule->source_attribute.' '.$rule->operator.' '.$rule->target_attribute;
        }

        return [
            'compatible' => empty($errors),
            'warnings' => $warnings,
            'errors' => $errors,
            'recommendations' => $recommendations,
        ];
    }

    private function pair(PcBuild $build, string $sourceType, string $targetType, string $sourceAttribute, string $targetAttribute, string $ok, array &$recommendations, array &$warnings, array &$errors, bool $allowContains = false): void
    {
        $source = $this->product($build, $sourceType);
        $target = $this->product($build, $targetType);

        if (! $source || ! $target) {
            return;
        }

        $sourceValue = $this->attribute($source, $sourceAttribute);
        $targetValue = $this->attribute($target, $targetAttribute);

        if (! $sourceValue || ! $targetValue) {
            $warnings[] = "Missing {$sourceAttribute}/{$targetAttribute} data for {$sourceType} and {$targetType}.";

            return;
        }

        $match = $allowContains
            ? str_contains(mb_strtolower($targetValue), mb_strtolower($sourceValue)) || str_contains(mb_strtolower($sourceValue), mb_strtolower($targetValue))
            : mb_strtolower($sourceValue) === mb_strtolower($targetValue);

        if (! $match) {
            $errors[] = "{$sourceType} {$sourceAttribute} ({$sourceValue}) is not compatible with {$targetType} {$targetAttribute} ({$targetValue}).";

            return;
        }

        $recommendations[] = $ok.'.';
    }

    private function power(PcBuild $build, array &$recommendations, array &$warnings): void
    {
        $gpu = $this->product($build, 'gpu');
        $psu = $this->product($build, 'psu');

        if (! $gpu || ! $psu) {
            return;
        }

        $required = (int) $this->attribute($gpu, 'recommended_psu_watts');
        $available = (int) $this->attribute($psu, 'wattage');

        if ($required && $available && $available < $required) {
            $warnings[] = "PSU may be insufficient: {$available}W available, {$required}W recommended.";
        } elseif ($required && $available) {
            $recommendations[] = 'PSU wattage looks sufficient.';
        }
    }

    private function product(PcBuild $build, string $type): ?Product
    {
        return $build->items->firstWhere('component_type', $type)?->product;
    }

    private function attribute(Product $product, string $slug): ?string
    {
        $slugs = $this->canonicalAliases($slug);
        $assignment = $product->attributeValues->first(function ($assignment) use ($slugs): bool {
            $code = $assignment->canonicalAttribute?->code ?? $assignment->attribute?->slug;

            return in_array($code, $slugs, true);
        });

        return $assignment?->canonicalAttributeValue?->display_value ?? $assignment?->value?->value ?? $assignment?->custom_value;
    }

    private function canonicalAliases(string $slug): array
    {
        return match ($slug) {
            'socket' => ['cpu_socket', 'motherboard_socket', 'cooler_socket_support', 'socket'],
            'wattage' => ['psu_wattage', 'wattage'],
            'recommended_psu_watts' => ['recommended_psu_watts', 'psu_wattage'],
            'storage_interface' => ['storage_type', 'storage_interface'],
            default => [$slug],
        };
    }
}
