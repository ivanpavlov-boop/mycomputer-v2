<?php

namespace App\Models\Concerns;

use App\Exceptions\ImportHistoryReferenceProtectedException;

trait GuardsImportHistoryIdentity
{
    /** @return array<int, string> */
    abstract protected function importHistoryIdentityFields(): array;

    abstract protected function importHistoryIdentityException(): ImportHistoryReferenceProtectedException;

    abstract public function hasImportHistoryReferences(): bool;

    /**
     * @param  array<int, string>  $candidateFields
     */
    protected function guardImportHistoryIdentityMutation(array $candidateFields, callable $mutation): mixed
    {
        $identityFields = array_values(array_intersect($this->importHistoryIdentityFields(), $candidateFields));
        $originalKey = $this->getRawOriginal($this->getKeyName()) ?? $this->getKey();

        if ($identityFields === [] || ! $this->exists || $originalKey === null) {
            return $mutation();
        }

        return $this->getConnection()->transaction(function () use ($mutation, $originalKey): mixed {
            $persisted = $this->newQueryWithoutRelationships()
                ->whereKey($originalKey)
                ->lockForUpdate()
                ->first();

            if ($persisted === null || ! $persisted->hasImportHistoryReferences()) {
                return $mutation();
            }

            $this->setRawAttributes($persisted->getAttributes(), true);
            $this->setRelations([]);

            throw $this->importHistoryIdentityException();
        });
    }
}
