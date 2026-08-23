<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\MassAssignmentException;

trait GuardsCanonicalSupplierMassAssignment
{
    protected $guarded = ['*'];

    public function fill(array $attributes)
    {
        if ($attributes !== []) {
            throw new MassAssignmentException('Canonical supplier records do not support mass assignment.');
        }

        return $this;
    }

    public function forceFill(array $attributes)
    {
        return $this->fill($attributes);
    }
}
