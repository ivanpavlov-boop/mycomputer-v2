<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PcBuildItem extends Model
{
    public const COMPONENT_TYPES = [
        'cpu',
        'motherboard',
        'ram',
        'gpu',
        'psu',
        'case',
        'storage',
        'cooler',
        'operating_system',
        'monitor',
        'keyboard',
        'mouse',
        'speakers',
        'accessories',
    ];

    protected $fillable = ['pc_build_id', 'product_id', 'component_type', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function build(): BelongsTo
    {
        return $this->belongsTo(PcBuild::class, 'pc_build_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
