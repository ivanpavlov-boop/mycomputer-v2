<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentTemplate extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'template_data'];

    protected function casts(): array
    {
        return ['template_data' => 'array'];
    }

    public function pages(): HasMany
    {
        return $this->hasMany(ContentPage::class, 'template_id');
    }
}
