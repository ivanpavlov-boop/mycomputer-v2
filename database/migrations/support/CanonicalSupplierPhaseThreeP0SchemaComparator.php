<?php

namespace Database\Migrations\Support;

use JsonException;

final class CanonicalSupplierPhaseThreeP0SchemaComparator
{
    public const UNCLASSIFIED_STATE = 'UNCLASSIFIED_P0_SCHEMA_STATE';

    public const ENVIRONMENT_DERIVED_DATABASE_COLLATION = 'ENVIRONMENT_DERIVED_DATABASE_COLLATION';

    private const SIGNATURE_DOMAIN = 'phase-iii-p0-schema-signature-v1';

    /** @var array<string, list<string>> */
    private const SIGNATURE_FIELDS = [
        'table' => [
            'type', 'table', 'table_type', 'engine', 'row_format',
            'table_collation', 'create_options', 'table_comment',
        ],
        'column' => [
            'type', 'table', 'ordinal', 'name', 'column_type', 'nullable',
            'default_kind', 'default_value', 'extra', 'charset', 'collation',
            'generation_expression', 'comment',
        ],
        'index' => [
            'type', 'table', 'name', 'non_unique', 'index_type', 'parts',
        ],
        'foreign_key' => [
            'type', 'name', 'child_table', 'child_columns', 'parent_table',
            'parent_columns', 'update_rule', 'delete_rule',
        ],
        'check' => ['type', 'name', 'table', 'clause', 'enforced'],
        'trigger' => [
            'type', 'name', 'table', 'timing', 'event', 'action_orientation',
            'action_statement', 'sql_mode', 'character_set_client',
            'collation_connection', 'database_collation',
        ],
    ];

    /**
     * @param  list<array<string, mixed>>  $candidateSignatures
     * @return array{state: string, classification: string, sha256: ?string, object_count: int}
     */
    public function classify(array $candidateSignatures): array
    {
        $candidate = $this->recordsByObjectId($candidateSignatures);

        if ($candidate === null) {
            return $this->unclassified(count($candidateSignatures));
        }

        foreach ($this->classifiableStates() as $state) {
            $expected = CanonicalSupplierPhaseThreeP0Oracle::signaturesFor($state['state']);

            if (array_keys($candidate) !== array_keys($expected)) {
                continue;
            }

            $matches = true;
            foreach ($candidate as $objectId => $record) {
                if ($record['sha256'] !== $expected[$objectId]['sha256']
                    || $record['canonical_json'] !== $expected[$objectId]['canonical_json']) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                return [
                    'state' => $state['state'],
                    'classification' => $state['classification'],
                    'sha256' => $state['sha256'],
                    'object_count' => count($candidate),
                ];
            }
        }

        return $this->unclassified(count($candidate));
    }

    /**
     * @param  list<array<string, mixed>>  $rawSignatures
     * @return array{state: string, classification: string, sha256: ?string, object_count: int}
     */
    public function classifyObserved(
        array $rawSignatures,
        mixed $schemaCharset,
        mixed $schemaDefaultCollation,
    ): array {
        $canonicalSignatures = $this->normalizeObservedSignatures(
            $rawSignatures,
            $schemaCharset,
            $schemaDefaultCollation,
        );

        if ($canonicalSignatures === null) {
            return $this->unclassified(count($rawSignatures));
        }

        return $this->classify($canonicalSignatures);
    }

    /**
     * Raw inspection evidence is never modified. Only an attested copy receives
     * the canonical environment-derived trigger token.
     *
     * @param  list<array<string, mixed>>  $rawSignatures
     * @return list<array<string, mixed>>|null
     */
    public function normalizeObservedSignatures(
        array $rawSignatures,
        mixed $schemaCharset,
        mixed $schemaDefaultCollation,
    ): ?array {
        if (! array_is_list($rawSignatures)
            || $schemaCharset !== 'utf8mb4'
            || ! is_string($schemaDefaultCollation)
            || $schemaDefaultCollation === '') {
            return null;
        }

        $canonicalSignatures = [];
        foreach ($rawSignatures as $rawSignature) {
            if (! is_array($rawSignature)) {
                return null;
            }

            $canonicalSignature = $rawSignature;
            if (($rawSignature['type'] ?? null) === 'trigger') {
                $rawDatabaseCollation = $rawSignature['database_collation'] ?? null;
                if (! array_key_exists('database_collation', $rawSignature)
                    || ! is_string($rawDatabaseCollation)
                    || $rawDatabaseCollation === ''
                    || ! hash_equals($schemaDefaultCollation, $rawDatabaseCollation)) {
                    return null;
                }

                $canonicalSignature['database_collation'] = self::ENVIRONMENT_DERIVED_DATABASE_COLLATION;
            }

            $canonicalSignatures[] = $canonicalSignature;
        }

        return $canonicalSignatures;
    }

    /** @return list<array<string, mixed>> */
    public function expectedSignatures(string $state): array
    {
        return array_values(array_map(
            static fn (array $record): array => $record['signature'],
            CanonicalSupplierPhaseThreeP0Oracle::signaturesFor($state),
        ));
    }

    /** @param array<string, mixed> $signature */
    public function canonicalJson(array $signature): ?string
    {
        $type = $signature['type'] ?? null;

        if (! is_string($type) || ! isset(self::SIGNATURE_FIELDS[$type])) {
            return null;
        }

        $fields = self::SIGNATURE_FIELDS[$type];
        if (array_keys($signature) !== $fields) {
            return null;
        }

        if (! $this->hasExactTypes($type, $signature)) {
            return null;
        }

        try {
            return json_encode(
                $signature,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            return null;
        }
    }

    /** @param array<string, mixed> $signature */
    public function objectId(array $signature): ?string
    {
        return match ($signature['type'] ?? null) {
            'table' => isset($signature['table']) && is_string($signature['table'])
                ? 'table:'.$signature['table']
                : null,
            'column' => isset($signature['table'], $signature['ordinal'], $signature['name'])
                && is_string($signature['table'])
                && is_int($signature['ordinal'])
                && is_string($signature['name'])
                    ? sprintf('column:%s:%03d:%s', $signature['table'], $signature['ordinal'], $signature['name'])
                    : null,
            'index' => $this->namedObjectId('index', $signature),
            'foreign_key' => $this->namedObjectId('foreign_key', $signature, 'child_table'),
            'check' => $this->namedObjectId('check', $signature),
            'trigger' => $this->namedObjectId('trigger', $signature),
            default => null,
        };
    }

    /** @return list<array<string, mixed>> */
    private function classifiableStates(): array
    {
        return array_values(array_filter(
            CanonicalSupplierPhaseThreeP0Oracle::states(),
            static fn (array $state): bool => $state['state'] !== 'P0_BASELINE',
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $signatures
     * @return array<string, array{canonical_json: string, sha256: string}>|null
     */
    private function recordsByObjectId(array $signatures): ?array
    {
        if (! array_is_list($signatures)) {
            return null;
        }

        $records = [];
        foreach ($signatures as $signature) {
            if (! is_array($signature)) {
                return null;
            }

            $objectId = $this->objectId($signature);
            $canonicalJson = $this->canonicalJson($signature);
            if ($objectId === null || $canonicalJson === null || isset($records[$objectId])) {
                return null;
            }

            $records[$objectId] = [
                'canonical_json' => $canonicalJson,
                'sha256' => hash('sha256', self::SIGNATURE_DOMAIN."\0".$canonicalJson),
            ];
        }

        ksort($records, SORT_STRING);

        return $records;
    }

    /** @param array<string, mixed> $signature */
    private function namedObjectId(string $type, array $signature, string $tableField = 'table'): ?string
    {
        return isset($signature[$tableField], $signature['name'])
            && is_string($signature[$tableField])
            && is_string($signature['name'])
                ? $type.':'.$signature[$tableField].':'.$signature['name']
                : null;
    }

    /** @param array<string, mixed> $signature */
    private function hasExactTypes(string $type, array $signature): bool
    {
        if (! is_string($signature['type'])) {
            return false;
        }

        if ($type === 'column') {
            return is_int($signature['ordinal'])
                && is_bool($signature['nullable'])
                && ($signature['default_value'] === null || is_string($signature['default_value']))
                && ($signature['charset'] === null || is_string($signature['charset']))
                && ($signature['collation'] === null || is_string($signature['collation']));
        }

        if ($type === 'index') {
            if (! is_int($signature['non_unique'])
                || ! is_array($signature['parts'])
                || ! array_is_list($signature['parts'])) {
                return false;
            }

            foreach ($signature['parts'] as $part) {
                if (! is_array($part)
                    || array_keys($part) !== ['sequence', 'column', 'expression', 'sub_part', 'collation']
                    || ! is_int($part['sequence'])
                    || ($part['column'] !== null && ! is_string($part['column']))
                    || ($part['expression'] !== null && ! is_string($part['expression']))
                    || ($part['sub_part'] !== null && ! is_int($part['sub_part']))
                    || ($part['collation'] !== null && ! is_string($part['collation']))) {
                    return false;
                }
            }
        }

        if ($type === 'foreign_key') {
            return $this->isStringList($signature['child_columns'])
                && $this->isStringList($signature['parent_columns']);
        }

        if ($type === 'check') {
            return is_bool($signature['enforced']);
        }

        foreach ($signature as $key => $value) {
            if ($key === 'type') {
                continue;
            }

            if (! is_string($value) && $value !== null && ! is_int($value) && ! is_bool($value) && ! is_array($value)) {
                return false;
            }
        }

        return true;
    }

    private function isStringList(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                return false;
            }
        }

        return true;
    }

    /** @return array{state: string, classification: string, sha256: null, object_count: int} */
    private function unclassified(int $objectCount): array
    {
        return [
            'state' => self::UNCLASSIFIED_STATE,
            'classification' => self::UNCLASSIFIED_STATE,
            'sha256' => null,
            'object_count' => $objectCount,
        ];
    }
}
