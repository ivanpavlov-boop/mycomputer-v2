<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

trait GuardsImmutableCanonicalSupplierRecord
{
    public function save(array $options = [])
    {
        if ($this->exists) {
            $this->rejectImmutableMutation();
        }

        return parent::save($options);
    }

    public function update(array $attributes = [], array $options = [])
    {
        $this->rejectImmutableMutation();
    }

    public function updateQuietly(array $attributes = [], array $options = [])
    {
        $this->rejectImmutableMutation();
    }

    public function updateOrFail(array $attributes = [], array $options = [])
    {
        $this->rejectImmutableMutation();
    }

    public function touch($attribute = null)
    {
        $this->rejectImmutableMutation();
    }

    protected function performUpdate(Builder $query)
    {
        $this->rejectImmutableMutation();
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        $this->rejectImmutableMutation();
    }

    public function delete()
    {
        $this->rejectImmutableMutation();
    }

    public function forceDelete()
    {
        return $this->delete();
    }

    private function rejectImmutableMutation(): never
    {
        throw new LogicException('Immutable canonical supplier records cannot be mutated.');
    }
}
