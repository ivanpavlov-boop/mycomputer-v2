<?php

namespace App\Services\Attributes;

use App\Models\CanonicalAttribute;
use App\Models\CanonicalAttributeValue;
use Illuminate\Support\Collection;

class DuplicateAttributeDetectionService
{
    public function __construct(private readonly AttributeTextNormalizer $text) {}

    public function attributes(): array
    {
        return $this->duplicateAttributes()
            ->map(fn (Collection $items): array => $items->pluck('name', 'id')->all())
            ->all();
    }

    public function values(): array
    {
        return $this->duplicateValues()
            ->map(fn (Collection $items): array => $items->pluck('display_value', 'id')->all())
            ->all();
    }

    public function duplicateAttributes(): Collection
    {
        return CanonicalAttribute::query()
            ->get()
            ->groupBy(fn (CanonicalAttribute $attribute): string => $this->text->normalize($attribute->name))
            ->filter(fn (Collection $items): bool => $items->count() > 1);
    }

    public function duplicateValues(): Collection
    {
        return CanonicalAttributeValue::query()
            ->with('canonicalAttribute')
            ->get()
            ->groupBy(fn (CanonicalAttributeValue $value): string => $value->canonical_attribute_id.'|'.$this->text->normalize($value->display_value))
            ->filter(fn (Collection $items): bool => $items->count() > 1);
    }
}
