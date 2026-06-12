<?php

namespace App\Services\Attributes;

use App\Models\AttributeGroup;
use App\Models\AttributeValue;
use App\Models\CanonicalAttribute;
use App\Models\CanonicalAttributeValue;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\SupplierProductAttribute;

class CatalogAttributeWriter
{
    public function write(Product $product, CanonicalAttribute $canonicalAttribute, CanonicalAttributeValue $canonicalValue): ProductAttributeValue
    {
        $group = AttributeGroup::query()->firstOrCreate(
            ['slug' => str($canonicalAttribute->group_name ?: 'Specifications')->slug()->toString()],
            [
                'name' => $canonicalAttribute->group_name ?: 'Specifications',
                'is_active' => true,
            ],
        );

        $attribute = ProductAttribute::query()->firstOrCreate(
            ['slug' => $canonicalAttribute->code],
            [
                'attribute_group_id' => $group->id,
                'name' => $canonicalAttribute->name,
                'type' => $canonicalAttribute->type === 'multiselect' ? 'select' : $canonicalAttribute->type,
                'unit' => $canonicalAttribute->unit,
                'is_filterable' => $canonicalAttribute->is_filterable,
                'is_required' => $canonicalAttribute->is_required,
                'is_active' => $canonicalAttribute->is_active,
                'sort_order' => $canonicalAttribute->sort_order,
            ],
        );

        $attribute->update([
            'attribute_group_id' => $group->id,
            'name' => $canonicalAttribute->name,
            'unit' => $canonicalAttribute->unit,
            'is_filterable' => $canonicalAttribute->is_filterable,
            'is_required' => $canonicalAttribute->is_required,
            'is_active' => $canonicalAttribute->is_active,
            'sort_order' => $canonicalAttribute->sort_order,
        ]);

        $value = AttributeValue::query()->firstOrCreate(
            [
                'product_attribute_id' => $attribute->id,
                'slug' => $canonicalValue->normalized_value,
            ],
            [
                'value' => $canonicalValue->display_value,
                'sort_order' => $canonicalValue->sort_order,
                'is_active' => $canonicalValue->is_active,
            ],
        );

        $value->update([
            'value' => $canonicalValue->display_value,
            'sort_order' => $canonicalValue->sort_order,
            'is_active' => $canonicalValue->is_active,
        ]);

        return ProductAttributeValue::query()->updateOrCreate(
            [
                'product_id' => $product->id,
                'product_attribute_id' => $attribute->id,
                'attribute_value_id' => $value->id,
            ],
            [
                'canonical_attribute_id' => $canonicalAttribute->id,
                'canonical_attribute_value_id' => $canonicalValue->id,
                'custom_value' => $canonicalValue->display_value,
                'is_filterable' => $canonicalAttribute->is_filterable,
            ],
        );
    }

    public function writeMappedSupplierAttribute(SupplierProductAttribute $raw, Product $product): ?ProductAttributeValue
    {
        $raw->loadMissing(['canonicalAttribute', 'canonicalAttributeValue']);

        if ($raw->status !== 'mapped' || ! $raw->canonicalAttribute || ! $raw->canonicalAttributeValue) {
            return null;
        }

        return $this->write($product, $raw->canonicalAttribute, $raw->canonicalAttributeValue);
    }
}
