<?php

namespace Database\Migrations\Support;

use PDO;
use RuntimeException;

final readonly class CanonicalSupplierPhaseThreeP0SchemaInspector
{
    /** @var list<string> */
    private const SHARED_TABLES = [
        'supplier_feeds',
        'supplier_import_execution_claims',
        'supplier_offer_snapshot_generations',
        'supplier_products',
    ];

    private const OWNER_MARKER = 'mycomputer:phase-iii-p0:v1:owner=';

    public function __construct(private PDO $pdo) {}

    /**
     * @return array{
     *     database: string,
     *     schema_charset: mixed,
     *     schema_default_collation: mixed,
     *     raw_signatures: list<array<string, mixed>>
     * }
     */
    public function inspect(): array
    {
        $database = $this->databaseName();
        $environment = $this->schemaEnvironment($database);
        $tables = $this->tableSignatures($database);
        $tableNames = array_map(
            static fn (array $signature): string => $signature['table'],
            $tables,
        );

        if ($tableNames === []) {
            return [
                'database' => $database,
                ...$environment,
                'raw_signatures' => [],
            ];
        }

        return [
            'database' => $database,
            ...$environment,
            'raw_signatures' => [
                ...$tables,
                ...$this->columnSignatures($database, $tableNames),
                ...$this->indexSignatures($database, $tableNames),
                ...$this->foreignKeySignatures($database, $tableNames),
                ...$this->checkSignatures($database, $tableNames),
                ...$this->triggerSignatures($database, $tableNames),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function enumerate(): array
    {
        return $this->inspect()['raw_signatures'];
    }

    private function databaseName(): string
    {
        $value = $this->pdo->query('SELECT DATABASE()')->fetchColumn();

        if (! is_string($value) || $value === '') {
            throw new RuntimeException('phase_three_p0_schema_inspection_unavailable');
        }

        return $value;
    }

    /** @return array{schema_charset: mixed, schema_default_collation: mixed} */
    private function schemaEnvironment(string $database): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME
            FROM information_schema.SCHEMATA
            WHERE SCHEMA_NAME = ?
            SQL);

        if ($statement === false || ! $statement->execute([$database])) {
            throw new RuntimeException('phase_three_p0_schema_inspection_unavailable');
        }

        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (! is_array($row)
            || ! array_key_exists('DEFAULT_CHARACTER_SET_NAME', $row)
            || ! array_key_exists('DEFAULT_COLLATION_NAME', $row)) {
            throw new RuntimeException('phase_three_p0_schema_inspection_unavailable');
        }

        return [
            'schema_charset' => $row['DEFAULT_CHARACTER_SET_NAME'],
            'schema_default_collation' => $row['DEFAULT_COLLATION_NAME'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function tableSignatures(string $database): array
    {
        $placeholders = implode(',', array_fill(0, count(self::SHARED_TABLES), '?'));
        $statement = $this->pdo->prepare(<<<SQL
            SELECT TABLE_NAME, TABLE_TYPE, ENGINE, ROW_FORMAT, TABLE_COLLATION,
                   CREATE_OPTIONS, TABLE_COMMENT
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = ?
              AND (
                TABLE_NAME IN ({$placeholders})
                OR TABLE_COMMENT LIKE ?
              )
            ORDER BY TABLE_NAME
            SQL);
        $statement->execute([
            $database,
            ...self::SHARED_TABLES,
            self::OWNER_MARKER.'%',
        ]);

        return array_map(static fn (array $row): array => [
            'type' => 'table',
            'table' => (string) $row['TABLE_NAME'],
            'table_type' => (string) $row['TABLE_TYPE'],
            'engine' => (string) $row['ENGINE'],
            'row_format' => (string) $row['ROW_FORMAT'],
            'table_collation' => (string) $row['TABLE_COLLATION'],
            'create_options' => (string) $row['CREATE_OPTIONS'],
            'table_comment' => (string) $row['TABLE_COMMENT'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<string> $tables @return list<array<string, mixed>> */
    private function columnSignatures(string $database, array $tables): array
    {
        $statement = $this->prepareForTables(<<<'SQL'
            SELECT TABLE_NAME, ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE,
                   IS_NULLABLE, COLUMN_DEFAULT, EXTRA, CHARACTER_SET_NAME,
                   COLLATION_NAME, GENERATION_EXPRESSION, COLUMN_COMMENT
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN (%s)
            ORDER BY TABLE_NAME, ORDINAL_POSITION
            SQL, $tables);
        $statement->execute([$database, ...$tables]);

        return array_map(static function (array $row): array {
            $default = $row['COLUMN_DEFAULT'];
            $extra = (string) $row['EXTRA'];

            return [
                'type' => 'column',
                'table' => (string) $row['TABLE_NAME'],
                'ordinal' => (int) $row['ORDINAL_POSITION'],
                'name' => (string) $row['COLUMN_NAME'],
                'column_type' => (string) $row['COLUMN_TYPE'],
                'nullable' => $row['IS_NULLABLE'] === 'YES',
                'default_kind' => $default === null
                    ? 'SQL_NULL'
                    : (str_contains($extra, 'DEFAULT_GENERATED') ? 'EXPRESSION' : 'LITERAL'),
                'default_value' => $default === null ? null : (string) $default,
                'extra' => $extra,
                'charset' => $row['CHARACTER_SET_NAME'] === null
                    ? null
                    : (string) $row['CHARACTER_SET_NAME'],
                'collation' => $row['COLLATION_NAME'] === null
                    ? null
                    : (string) $row['COLLATION_NAME'],
                'generation_expression' => self::normalizeSql((string) $row['GENERATION_EXPRESSION']),
                'comment' => (string) $row['COLUMN_COMMENT'],
            ];
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<string> $tables @return list<array<string, mixed>> */
    private function indexSignatures(string $database, array $tables): array
    {
        $statement = $this->prepareForTables(<<<'SQL'
            SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, INDEX_TYPE, SEQ_IN_INDEX,
                   COLUMN_NAME, EXPRESSION, SUB_PART, COLLATION
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN (%s)
            ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX
            SQL, $tables);
        $statement->execute([$database, ...$tables]);
        $grouped = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['TABLE_NAME']."\0".$row['INDEX_NAME'];
            $grouped[$key] ??= [
                'type' => 'index',
                'table' => (string) $row['TABLE_NAME'],
                'name' => (string) $row['INDEX_NAME'],
                'non_unique' => (int) $row['NON_UNIQUE'],
                'index_type' => (string) $row['INDEX_TYPE'],
                'parts' => [],
            ];
            $grouped[$key]['parts'][] = [
                'sequence' => (int) $row['SEQ_IN_INDEX'],
                'column' => $row['COLUMN_NAME'] === null ? null : (string) $row['COLUMN_NAME'],
                'expression' => $row['EXPRESSION'] === null
                    ? null
                    : self::normalizeSql((string) $row['EXPRESSION']),
                'sub_part' => $row['SUB_PART'] === null ? null : (int) $row['SUB_PART'],
                'collation' => $row['COLLATION'] === null ? null : (string) $row['COLLATION'],
            ];
        }

        return array_values($grouped);
    }

    /** @param list<string> $tables @return list<array<string, mixed>> */
    private function foreignKeySignatures(string $database, array $tables): array
    {
        $statement = $this->prepareForTables(<<<'SQL'
            SELECT k.CONSTRAINT_NAME, k.TABLE_NAME AS CHILD_TABLE,
                   k.COLUMN_NAME AS CHILD_COLUMN, k.REFERENCED_TABLE_NAME AS PARENT_TABLE,
                   k.REFERENCED_COLUMN_NAME AS PARENT_COLUMN, k.ORDINAL_POSITION,
                   r.UPDATE_RULE, r.DELETE_RULE
            FROM information_schema.KEY_COLUMN_USAGE k
            INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r
              ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
             AND r.TABLE_NAME = k.TABLE_NAME
             AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
            WHERE k.CONSTRAINT_SCHEMA = ?
              AND k.TABLE_NAME IN (%s)
              AND k.REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION
            SQL, $tables);
        $statement->execute([$database, ...$tables]);
        $grouped = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['CHILD_TABLE']."\0".$row['CONSTRAINT_NAME'];
            $grouped[$key] ??= [
                'type' => 'foreign_key',
                'name' => (string) $row['CONSTRAINT_NAME'],
                'child_table' => (string) $row['CHILD_TABLE'],
                'child_columns' => [],
                'parent_table' => (string) $row['PARENT_TABLE'],
                'parent_columns' => [],
                'update_rule' => (string) $row['UPDATE_RULE'],
                'delete_rule' => (string) $row['DELETE_RULE'],
            ];
            $grouped[$key]['child_columns'][] = (string) $row['CHILD_COLUMN'];
            $grouped[$key]['parent_columns'][] = (string) $row['PARENT_COLUMN'];
        }

        return array_values($grouped);
    }

    /** @param list<string> $tables @return list<array<string, mixed>> */
    private function checkSignatures(string $database, array $tables): array
    {
        $statement = $this->prepareForTables(<<<'SQL'
            SELECT tc.CONSTRAINT_NAME, tc.TABLE_NAME, cc.CHECK_CLAUSE, tc.ENFORCED
            FROM information_schema.TABLE_CONSTRAINTS tc
            INNER JOIN information_schema.CHECK_CONSTRAINTS cc
              ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
             AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
            WHERE tc.CONSTRAINT_SCHEMA = ?
              AND tc.TABLE_NAME IN (%s)
              AND tc.CONSTRAINT_TYPE = 'CHECK'
            ORDER BY tc.TABLE_NAME, tc.CONSTRAINT_NAME
            SQL, $tables);
        $statement->execute([$database, ...$tables]);

        return array_map(static fn (array $row): array => [
            'type' => 'check',
            'name' => (string) $row['CONSTRAINT_NAME'],
            'table' => (string) $row['TABLE_NAME'],
            'clause' => self::normalizeSql((string) $row['CHECK_CLAUSE']),
            'enforced' => $row['ENFORCED'] === 'YES',
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<string> $tables @return list<array<string, mixed>> */
    private function triggerSignatures(string $database, array $tables): array
    {
        $statement = $this->prepareForTables(<<<'SQL'
            SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING,
                   EVENT_MANIPULATION, ACTION_ORIENTATION, ACTION_STATEMENT,
                   SQL_MODE, CHARACTER_SET_CLIENT, COLLATION_CONNECTION,
                   DATABASE_COLLATION
            FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = ? AND EVENT_OBJECT_TABLE IN (%s)
            ORDER BY EVENT_OBJECT_TABLE, TRIGGER_NAME
            SQL, $tables);
        $statement->execute([$database, ...$tables]);

        return array_map(static fn (array $row): array => [
            'type' => 'trigger',
            'name' => (string) $row['TRIGGER_NAME'],
            'table' => (string) $row['EVENT_OBJECT_TABLE'],
            'timing' => (string) $row['ACTION_TIMING'],
            'event' => (string) $row['EVENT_MANIPULATION'],
            'action_orientation' => (string) $row['ACTION_ORIENTATION'],
            'action_statement' => self::normalizeSql((string) $row['ACTION_STATEMENT']),
            'sql_mode' => (string) $row['SQL_MODE'],
            'character_set_client' => (string) $row['CHARACTER_SET_CLIENT'],
            'collation_connection' => (string) $row['COLLATION_CONNECTION'],
            'database_collation' => $row['DATABASE_COLLATION'] === null
                ? null
                : (string) $row['DATABASE_COLLATION'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<string> $tables */
    private function prepareForTables(string $sql, array $tables): \PDOStatement
    {
        $placeholders = implode(',', array_fill(0, count($tables), '?'));
        $statement = $this->pdo->prepare(sprintf($sql, $placeholders));

        if ($statement === false) {
            throw new RuntimeException('phase_three_p0_schema_inspection_unavailable');
        }

        return $statement;
    }

    private static function normalizeSql(string $sql): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $sql), " \t\n\r\0\x0B");
    }
}
