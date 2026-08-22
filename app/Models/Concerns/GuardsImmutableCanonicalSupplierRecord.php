<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

trait GuardsImmutableCanonicalSupplierRecord
{
    protected function performUpdate(Builder $query)
    {
        throw new LogicException('Immutable canonical supplier records cannot be updated.');
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        throw new LogicException('Immutable canonical supplier records cannot be updated.');
    }

    public function delete()
    {
        throw new LogicException('Immutable canonical supplier records cannot be deleted.');
    }

    public function forceDelete()
    {
        return $this->delete();
    }
}
