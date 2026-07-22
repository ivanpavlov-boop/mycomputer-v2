<?php

namespace App\Enums;

use App\Models\ProductAttribute;

enum CategoryAttributeFilterControl: string
{
    case Auto = 'auto';
    case Options = 'options';
    case YesNo = 'yes_no';
    case RangeSlider = 'range_slider';
    case MinMax = 'min_max';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $control): array => [$control->value => $control->label()])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function optionsForAttributeType(?string $attributeType): array
    {
        return collect(self::allowedForAttributeType($attributeType))
            ->mapWithKeys(fn (self $control): array => [$control->value => $control->label()])
            ->all();
    }

    /**
     * @return array<int, self>
     */
    public static function allowedForAttributeType(?string $attributeType): array
    {
        return match ($attributeType) {
            ProductAttribute::TYPE_SELECT,
            ProductAttribute::TYPE_MULTISELECT => [self::Auto, self::Options],
            ProductAttribute::TYPE_BOOLEAN => [self::Auto, self::YesNo],
            ProductAttribute::TYPE_NUMBER,
            ProductAttribute::TYPE_DECIMAL => [self::Auto, self::RangeSlider, self::MinMax],
            default => [self::Auto],
        };
    }

    public static function fromPersisted(mixed $value): self
    {
        return is_string($value) ? self::tryFrom($value) ?? self::Auto : self::Auto;
    }

    public function label(): string
    {
        return match ($this) {
            self::Auto => 'Автоматично',
            self::Options => 'Избор от стойности',
            self::YesNo => 'Да / Не',
            self::RangeSlider => 'Плъзгач',
            self::MinMax => 'Начална и крайна стойност',
        };
    }

    public function isCompatibleWith(?string $attributeType): bool
    {
        return in_array($this, self::allowedForAttributeType($attributeType), true);
    }

    public function resolveForAttributeType(?string $attributeType): ?self
    {
        if ($this !== self::Auto) {
            return $this->isCompatibleWith($attributeType) ? $this : null;
        }

        return match ($attributeType) {
            ProductAttribute::TYPE_SELECT,
            ProductAttribute::TYPE_MULTISELECT => self::Options,
            ProductAttribute::TYPE_BOOLEAN => self::YesNo,
            ProductAttribute::TYPE_NUMBER,
            ProductAttribute::TYPE_DECIMAL => self::MinMax,
            default => null,
        };
    }

    public function validationMessage(): string
    {
        return match ($this) {
            self::RangeSlider, self::MinMax => 'Избраният числов контрол може да се използва само с числова характеристика.',
            self::YesNo => 'Типът „Да / Не“ може да се използва само с булева характеристика.',
            self::Options => '„Избор от стойности“ изисква характеристика от тип избор или множествен избор.',
            default => 'Избраният тип публичен филтър не е съвместим с характеристиката.',
        };
    }
}
