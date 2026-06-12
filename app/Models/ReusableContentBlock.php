<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReusableContentBlock extends Model
{
    protected $fillable = ['name', 'block_type', 'settings', 'content', 'responsive_settings'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'content' => 'array',
            'responsive_settings' => 'array',
        ];
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(ContentBlock::class, 'reusable_block_id');
    }
}
