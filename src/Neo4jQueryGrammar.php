<?php

namespace Neo4j\Neo4jLaravel;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use InvalidArgumentException;
use RuntimeException;
use WikibaseSolutions\CypherDSL\Expressions\Operators\In;
use WikibaseSolutions\CypherDSL\Expressions\Parameter;
use WikibaseSolutions\CypherDSL\Expressions\Property;
use WikibaseSolutions\CypherDSL\Patterns\Node;
use WikibaseSolutions\CypherDSL\Query;
use WikibaseSolutions\CypherDSL\Types\PropertyTypes\BooleanType;

/**
 * Neo4j Cypher query grammar backed by php-cypher-dsl.
 *
 * Supported today (select path):
 *   - from() / table() -> MATCH (n:Label)
 *   - select(columns)  -> RETURN n.col, ...
 *   - where (Basic, Null, NotNull, In, NotIn, Between/NotBetween, Nested,
 *            Column, raw, Date, Time, Day, Month, Year)
 *   - whereVectorSimilarTo -> CALL db.index.vector.queryNodes
 *   - aggregates, exists()
 *   - groupBy / having (basic)
 *   - orderBy -> ORDER BY
 *   - limit   -> LIMIT
 *   - offset  -> SKIP
 *   - union / unionAll
 *   - insert   -> CREATE
 *   - update   -> MATCH / SET (including increment/decrement expressions)
 *   - delete   -> MATCH / DETACH DELETE
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class Neo4jQueryGrammar extends Grammar
{
    private int $parameterIndex = 0;

    public function __construct()
    {
        // Connection may be injected via setConnection() on Laravel 11+.
    }

    /**
     * Whether this grammar supports Laravel vector-distance query methods.
     */
    public function supportsVectorDistance(): bool
    {
        return true;
    }

    /**
     * Compile a cosine-distance expression for the given embedding property.
     *
     * Laravel 13 uses this for pgvector; Neo4j vector search compiles the
     * full query via compileSelect() instead of embedding this in MATCH.
     */
    public function compileVectorDistanceExpression($column): string
    {
        $property = $this->compileColumn((string) $column)->toQuery();

        return "(1.0 - vector.similarity.cosine({$property}, \$queryVector))";
    }

    /**
     * Wrap a column so increment/decrement and columnize emit Cypher properties.
     *
     * @param  \Illuminate\Contracts\Database\Query\Expression|string  $value
     */
    #[\Override]
    public function wrap($value)
    {
        if ($this->isExpression($value)) {
            return $this->getValue($value);
        }

        if (! is_string($value) || $value === '') {
            return parent::wrap($value);
        }

        if ($value === '*') {
            return '*';
        }

        return $this->compileColumn($value)->toQuery();
    }

    #[\Override]
    protected function wrapValue($value)
    {
        if ($value === '*') {
            return $value;
        }

        $this->assertIdentifier($value);

        return $value;
    }

    #[\Override]
    public function compileSelect(Builder $query): string
    {
        $this->parameterIndex = 0;

        return $this->compileSelectBody($query);
    }

    /**
     * Compile a select without resetting the shared parameter counter (unions).
     */
    private function compileSelectBody(Builder $query): string
    {
        if ($this->hasVectorSimilarity($query)) {
            if (! empty($query->unions) || ! empty($query->groups) || ! empty($query->havings) || $query->aggregate !== null) {
                throw new RuntimeException('Vector similarity queries cannot be combined with union, groupBy, having, or aggregates.');
            }

            return $this->compileVectorSelect($query);
        }

        $sql = $this->compileMatchSelect($query);

        if (! empty($query->unions)) {
            foreach ($query->unions as $union) {
                $conjunction = ! empty($union['all']) ? ' UNION ALL ' : ' UNION ';
                $sql .= $conjunction.$this->compileMatchSelect($union['query']);
            }

            if (! empty($query->unionOrders)) {
                $orders = $this->compileOrders($query, $query->unionOrders);
                if ($orders !== '') {
                    $sql .= ' '.$orders;
                }
            }

            if (isset($query->unionOffset)) {
                $sql .= ' SKIP '.(int) $query->unionOffset;
            }

            if (isset($query->unionLimit)) {
                $sql .= ' LIMIT '.(int) $query->unionLimit;
            }
        }

        return $sql;
    }

    private function compileMatchSelect(Builder $query): string
    {
        $node = $this->compileNode($query->from);
        $prefix = Query::new()->match($node)->build();
        $where = $this->compileWhereExpression($query->wheres ?? [], $node);

        if ($where !== null) {
            $prefix .= ' '.Query::new()->where($where)->build();
        }

        if ($query->aggregate !== null) {
            return $this->compileAggregateSelect($query, $prefix, $node);
        }

        if (! empty($query->groups) || ! empty($query->havings)) {
            return $this->compileGroupedSelect($query, $prefix, $node);
        }

        $cypher = $prefix.' RETURN '.$this->compileReturnClause($query, $node);

        $orders = $this->compileOrders($query, $query->orders ?? []);
        if ($orders !== '') {
            $cypher .= ' '.$orders;
        }

        if ($query->offset !== null) {
            $cypher .= ' SKIP '.(int) $query->offset;
        }

        if ($query->limit !== null) {
            $cypher .= ' LIMIT '.(int) $query->limit;
        }

        return $cypher;
    }

    /**
     * @param  array{function: string, columns: array<int, mixed>}  $aggregate
     */
    private function compileAggregateSelect(Builder $query, string $prefix, Node $node): string
    {
        $aggregate = $query->aggregate;
        $function = strtolower((string) $aggregate['function']);
        $this->assertIdentifier($function);

        $argument = $this->compileAggregateArgument($function, $aggregate['columns'], (bool) $query->distinct);
        $aggregateReturn = "{$function}({$argument}) AS aggregate";

        if (! empty($query->groups) || ! empty($query->havings)) {
            $withParts = $this->compileGroupExpressions($query, $node);
            $withParts[] = $aggregateReturn;
            $cypher = $prefix.' WITH '.implode(', ', $withParts);

            if (! empty($query->havings)) {
                $cypher .= ' WHERE '.$this->compileNeo4jHavings($query);
            }

            $returnParts = [];
            foreach ($query->groups ?? [] as $group) {
                $returnParts[] = $this->groupAlias($group);
            }
            $returnParts[] = 'aggregate';

            return $cypher.' RETURN '.implode(', ', $returnParts);
        }

        return $prefix.' RETURN '.$aggregateReturn;
    }

    /**
     * @param  array<int, mixed>  $columns
     */
    private function compileAggregateArgument(string $function, array $columns, bool $distinct): string
    {
        $column = $columns[0] ?? '*';

        if ($this->isExpression($column)) {
            $argument = $this->getValue($column);
        } elseif ($column === '*' || $column === 'n.*') {
            if ($function !== 'count') {
                throw new RuntimeException("Aggregate {$function}() requires a column on Neo4j Query Builder.");
            }
            $argument = 'n';
        } else {
            $argument = $this->compileColumn((string) $column)->toQuery();
        }

        if ($distinct && $argument !== 'n') {
            return 'DISTINCT '.$argument;
        }

        return $argument;
    }

    private function compileGroupedSelect(Builder $query, string $prefix, Node $node): string
    {
        $withParts = $this->compileGroupExpressions($query, $node);

        foreach ($query->columns ?? [] as $column) {
            if (! is_string($column) || $column === '*') {
                continue;
            }

            $alias = $this->propertyName($column);
            $alreadyGrouped = false;
            foreach ($query->groups ?? [] as $group) {
                if (! $this->isExpression($group) && $this->propertyName((string) $group) === $alias) {
                    $alreadyGrouped = true;
                    break;
                }
            }

            if (! $alreadyGrouped) {
                $withParts[] = $this->compileColumn($column, $node)->toQuery().' AS '.$alias;
            }
        }

        if ($withParts === []) {
            throw new RuntimeException('groupBy() requires at least one grouping column on Neo4j Query Builder.');
        }

        // Without an aggregate, DISTINCT is required so Cypher collapses rows like SQL GROUP BY.
        $cypher = $prefix.' WITH DISTINCT '.implode(', ', $withParts);

        if (! empty($query->havings)) {
            $cypher .= ' WHERE '.$this->compileNeo4jHavings($query);
        }

        $returnParts = [];
        foreach ($query->groups ?? [] as $group) {
            $returnParts[] = $this->groupAlias($group);
        }

        foreach ($query->columns ?? [] as $column) {
            if (! is_string($column) || $column === '*') {
                continue;
            }
            $alias = $this->propertyName($column);
            if (! in_array($alias, $returnParts, true)) {
                $returnParts[] = $alias;
            }
        }

        if ($returnParts === []) {
            $returnParts = array_map(fn ($group) => $this->groupAlias($group), $query->groups ?? []);
        }

        $cypher .= ' RETURN '.implode(', ', $returnParts);

        $orders = $this->compileOrders($query, $query->orders ?? []);
        if ($orders !== '') {
            $cypher .= ' '.$orders;
        }

        if ($query->offset !== null) {
            $cypher .= ' SKIP '.(int) $query->offset;
        }

        if ($query->limit !== null) {
            $cypher .= ' LIMIT '.(int) $query->limit;
        }

        return $cypher;
    }

    /**
     * @return list<string>
     */
    private function compileGroupExpressions(Builder $query, Node $node): array
    {
        $parts = [];

        foreach ($query->groups ?? [] as $group) {
            if ($this->isExpression($group)) {
                $parts[] = $this->getValue($group);

                continue;
            }

            $property = $this->compileColumn((string) $group, $node)->toQuery();
            $parts[] = $property.' AS '.$this->propertyName((string) $group);
        }

        return $parts;
    }

    private function groupAlias(mixed $group): string
    {
        if ($this->isExpression($group)) {
            $value = trim($this->getValue($group));
            if (preg_match('/\s+AS\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $value, $matches) === 1) {
                return $matches[1];
            }

            throw new RuntimeException('Raw groupBy expressions must end with AS alias for Neo4j Query Builder.');
        }

        return $this->propertyName((string) $group);
    }

    private function compileNeo4jHavings(Builder $query): string
    {
        $parts = [];

        foreach ($query->havings ?? [] as $having) {
            $boolean = strtolower((string) ($having['boolean'] ?? 'and'));
            $compiled = $this->compileHaving($having);
            $parts[] = ($parts === [] ? '' : strtoupper($boolean).' ').$compiled;
        }

        return implode(' ', $parts);
    }

    /**
     * @param  array<string, mixed>  $having
     */
    #[\Override]
    protected function compileHaving(array $having): string
    {
        return match ($having['type'] ?? null) {
            'Raw' => (string) $having['sql'],
            'Null' => $this->havingColumnName($having).' IS NULL',
            'NotNull' => $this->havingColumnName($having).' IS NOT NULL',
            'between' => $this->compileNeo4jHavingBetween($having),
            'Nested' => '('.$this->compileNeo4jHavings($having['query']).')',
            'Expression' => $this->getValue($having['column']),
            default => $this->compileNeo4jBasicHaving($having),
        };
    }

    /**
     * @param  array<string, mixed>  $having
     */
    private function compileNeo4jBasicHaving(array $having): string
    {
        $column = $this->havingColumnName($having);
        $operator = $this->normalizeComparisonOperator((string) $having['operator']);
        $parameter = '$'.$this->nextParameterName();

        return "{$column} {$operator} {$parameter}";
    }

    /**
     * @param  array<string, mixed>  $having
     */
    private function compileNeo4jHavingBetween(array $having): string
    {
        $column = $this->havingColumnName($having);
        $low = '$'.$this->nextParameterName();
        $high = '$'.$this->nextParameterName();
        $clause = "{$column} >= {$low} AND {$column} <= {$high}";

        return ! empty($having['not']) ? "NOT ({$clause})" : $clause;
    }

    /**
     * @param  array<string, mixed>  $having
     */
    private function havingColumnName(array $having): string
    {
        $column = $having['column'] ?? null;

        if ($this->isExpression($column)) {
            return $this->getValue($column);
        }

        $name = (string) $column;

        if (str_contains($name, '.')) {
            return $this->propertyName($name);
        }

        $this->assertIdentifier($name);

        return $name;
    }

    private function compileReturnClause(Builder $query, Node $node): string
    {
        $columns = $query->columns ?? null;
        $distinct = $query->distinct ? 'DISTINCT ' : '';

        if ($columns === null || $columns === [] || $columns === ['*']) {
            return $distinct.'n';
        }

        $parts = [];
        foreach ($columns as $column) {
            if ($this->isExpression($column)) {
                $parts[] = $this->getValue($column);

                continue;
            }

            if (! is_string($column) || $column === '*') {
                return $distinct.'n';
            }
            $parts[] = $this->compileColumn($column, $node)->toQuery();
        }

        return $distinct.implode(', ', $parts);
    }

    /**
     * @param  array<int, array<string, mixed>>  $orders
     */
    #[\Override]
    public function compileOrders(Builder $query, $orders): string
    {
        if (empty($orders)) {
            return '';
        }

        $parts = [];
        foreach ($orders as $order) {
            if ($this->isExpression($order['column'] ?? null)) {
                $column = $this->getValue($order['column']);
            } else {
                $columnName = (string) $order['column'];
                // After WITH aliases (groupBy), order by the alias when present.
                $column = ! empty($query->groups) && ! str_contains($columnName, '.')
                    ? $this->propertyName($columnName)
                    : $this->compileColumn($columnName)->toQuery();
            }
            $direction = strtolower((string) ($order['direction'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
            $parts[] = "{$column} {$direction}";
        }

        return 'ORDER BY '.implode(', ', $parts);
    }

    #[\Override]
    public function compileWheres(Builder $query): string
    {
        $this->parameterIndex = 0;

        if (empty($query->wheres)) {
            return '';
        }

        $expression = $this->compileWhereExpression($query->wheres, $this->compileNode($query->from));

        return $expression === null ? '' : Query::new()->where($expression)->build();
    }

    #[\Override]
    public function compileExists(Builder $query): string
    {
        $this->parameterIndex = 0;

        if ($this->hasVectorSimilarity($query)) {
            throw new RuntimeException('exists() is not supported with whereVectorSimilarTo().');
        }

        $node = $this->compileNode($query->from);
        $cypher = Query::new()->match($node)->build();
        $where = $this->compileWhereExpression($query->wheres ?? [], $node);

        if ($where !== null) {
            $cypher .= ' '.Query::new()->where($where)->build();
        }

        return $cypher.' RETURN true AS exists LIMIT 1';
    }

    /**
     * @param  array<int, array<string, mixed>>  $wheres
     */
    private function compileWhereExpression(array $wheres, Node $node): ?BooleanType
    {
        $expression = null;

        foreach ($wheres as $where) {
            $clause = $this->compileWhereClause($where, $node);

            if ($expression === null) {
                $expression = $clause;

                continue;
            }

            $expression = strtolower((string) ($where['boolean'] ?? 'and')) === 'or'
                ? $expression->or($clause)
                : $expression->and($clause);
        }

        return $expression;
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private function compileWhereClause(array $where, Node $node): BooleanType
    {
        $type = $where['type'] ?? null;

        return match ($type) {
            'Basic' => $this->compileBasicWhere($where, $node),
            'Null' => $this->compileColumn((string) $where['column'], $node)->isNull(),
            'NotNull' => $this->compileColumn((string) $where['column'], $node)->isNotNull(),
            'In' => $this->compileInWhere($where, $node, false),
            'NotIn' => $this->compileInWhere($where, $node, true),
            'between' => $this->compileBetweenWhere($where, $node),
            'Nested' => $this->compileNestedWhere($where, $node),
            'Column' => $this->compileColumnWhere($where, $node),
            'raw', 'Raw' => $this->compileRawWhere($where),
            'Date' => $this->compileDateWhere($where, $node, 'date'),
            'Time' => $this->compileDateWhere($where, $node, 'time'),
            'Day' => $this->compileDatePartWhere($where, $node, 'day'),
            'Month' => $this->compileDatePartWhere($where, $node, 'month'),
            'Year' => $this->compileDatePartWhere($where, $node, 'year'),
            'VectorSimilar' => throw new RuntimeException('whereVectorSimilarTo() cannot be nested inside MATCH WHERE; it replaces the scan with a vector index query.'),
            default => throw new RuntimeException("Unsupported where type for Neo4j Query Builder: {$type}"),
        };
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private function compileBasicWhere(array $where, Node $node): BooleanType
    {
        $column = $this->compileColumn((string) $where['column'], $node);
        $operator = $this->normalizeOperator((string) $where['operator']);
        $param = $this->nextParameter();

        return match ($operator) {
            '=' => $column->equals($param),
            '<>' => $column->notEquals($param),
            '<' => $column->lt($param),
            '<=' => $column->lte($param),
            '>' => $column->gt($param),
            '>=' => $column->gte($param),
            'CONTAINS' => $column->contains($param),
            'STARTS WITH' => $column->startsWith($param),
            'ENDS WITH' => $column->endsWith($param),
            '=~' => $column->regex($param),
        };
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private function compileColumnWhere(array $where, Node $node): BooleanType
    {
        $first = $this->compileColumn((string) $where['first'], $node)->toQuery();
        $second = $this->compileColumn((string) $where['second'], $node)->toQuery();
        $operator = $this->normalizeComparisonOperator((string) $where['operator']);

        return Query::rawExpression("{$first} {$operator} {$second}");
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private function compileRawWhere(array $where): BooleanType
    {
        $sql = (string) $where['sql'];
        $sql = preg_replace_callback('/\?/', function (): string {
            return '$'.$this->nextParameterName();
        }, $sql) ?? $sql;

        return Query::rawExpression($sql);
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private function compileDateWhere(array $where, Node $node, string $function): BooleanType
    {
        $column = $this->compileColumn((string) $where['column'], $node)->toQuery();
        $operator = $this->normalizeComparisonOperator((string) $where['operator']);
        $parameter = '$'.$this->nextParameterName();
        $temporal = $this->temporalExpression($column);

        if ($function === 'date') {
            return Query::rawExpression("date({$temporal}) {$operator} date({$parameter})");
        }

        return Query::rawExpression("time({$temporal}) {$operator} time({$parameter})");
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private function compileDatePartWhere(array $where, Node $node, string $part): BooleanType
    {
        $column = $this->compileColumn((string) $where['column'], $node)->toQuery();
        $operator = $this->normalizeComparisonOperator((string) $where['operator']);
        $parameter = '$'.$this->nextParameterName();
        $temporal = $this->temporalExpression($column);

        return Query::rawExpression("{$temporal}.{$part} {$operator} toInteger({$parameter})");
    }

    private function temporalExpression(string $column): string
    {
        // Accept Neo4j temporal values or Laravel-style "Y-m-d H:i:s" strings.
        return "datetime(replace(toString({$column}), ' ', 'T'))";
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private function compileInWhere(array $where, Node $node, bool $negate): BooleanType
    {
        $column = $this->compileColumn((string) $where['column'], $node);
        $values = $where['values'] ?? [];

        $params = [];
        foreach ($values as $ignored) {
            $params[] = $this->nextParameter();
        }

        if ($params === []) {
            return Query::rawExpression($negate ? 'true' : 'false');
        }

        $clause = new In($column, Query::list($params));

        return $negate ? $clause->not() : $clause;
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private function compileBetweenWhere(array $where, Node $node): BooleanType
    {
        $column = $this->compileColumn((string) $where['column'], $node);
        $low = $this->nextParameter();
        $high = $this->nextParameter();

        $clause = $column->gte($low)->and($column->lte($high));

        return ! empty($where['not']) ? $clause->not() : $clause;
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private function compileNestedWhere(array $where, Node $node): BooleanType
    {
        $nested = $where['query'] ?? null;
        if (! $nested instanceof Builder) {
            throw new RuntimeException('Invalid nested where clause for Neo4j Query Builder.');
        }

        $expression = $this->compileWhereExpression($nested->wheres ?? [], $node);

        if ($expression === null) {
            throw new RuntimeException('Nested where clause for Neo4j Query Builder cannot be empty.');
        }

        return $expression;
    }

    /**
     * @param  array<int, array<string, mixed>>  $wheres
     */
    private function hasVectorSimilarity(Builder $query): bool
    {
        foreach ($query->wheres ?? [] as $where) {
            if (($where['type'] ?? null) === 'VectorSimilar') {
                return true;
            }
        }

        return false;
    }

    private function compileVectorSelect(Builder $query): string
    {
        $node = $this->compileNode($query->from);
        $vectorWhere = null;
        $vectorParameter = null;
        $minSimilarityParameter = null;
        $extraFilters = [];

        foreach ($query->wheres ?? [] as $where) {
            if (($where['type'] ?? null) === 'VectorSimilar') {
                if ($vectorWhere !== null) {
                    throw new RuntimeException('Only one whereVectorSimilarTo() clause is supported per query.');
                }

                $vectorWhere = $where;
                $vectorParameter = $this->nextParameterName();
                $minSimilarityParameter = $this->nextParameterName();

                continue;
            }

            $extraFilters[] = $this->compileWhereClause($where, $node)->toQuery();
        }

        if ($vectorWhere === null || $vectorParameter === null || $minSimilarityParameter === null) {
            throw new RuntimeException('Vector similarity query is missing a whereVectorSimilarTo() clause.');
        }

        $column = $this->propertyName((string) $vectorWhere['column']);
        $index = $this->vectorIndexName($query, $column);
        $limit = max(1, (int) ($query->limit ?? 10));
        $offset = (int) ($query->offset ?? 0);
        $k = $limit + $offset;

        $filters = ["score >= \${$minSimilarityParameter}"];
        foreach ($extraFilters as $filter) {
            $filters[] = $filter;
        }

        $cypher = sprintf(
            'CALL db.index.vector.queryNodes(\'%s\', %d, $%s) YIELD node AS n, score WHERE %s RETURN %s',
            $index,
            $k,
            $vectorParameter,
            implode(' AND ', $filters),
            $this->compileVectorReturn($query)
        );

        if (! empty($vectorWhere['order'])) {
            $cypher .= ' ORDER BY score DESC';
        } else {
            $orders = $this->compileOrders($query, $query->orders ?? []);
            if ($orders !== '') {
                $cypher .= ' '.$orders;
            }
        }

        if ($offset > 0) {
            $cypher .= ' SKIP '.$offset;
        }

        return $cypher.' LIMIT '.$limit;
    }

    private function compileVectorReturn(Builder $query): string
    {
        $columns = $query->columns ?? null;
        $distinct = $query->distinct ? 'DISTINCT ' : '';

        if ($columns === null || $columns === [] || $columns === ['*']) {
            return $distinct.'n, score';
        }

        $parts = [];
        foreach ($columns as $column) {
            if (! is_string($column) || $column === '*') {
                return $distinct.'n, score';
            }
            $parts[] = $this->compileColumn($column)->toQuery();
        }

        $parts[] = 'score';

        return $distinct.implode(', ', $parts);
    }

    private function vectorIndexName(Builder $query, string $column): string
    {
        $explicit = $query instanceof Neo4jQueryBuilder ? $query->vectorIndex : null;
        if (is_string($explicit) && $explicit !== '') {
            $this->assertIdentifier($explicit);

            return $explicit;
        }

        $hint = $query->indexHint ?? null;
        if (is_object($hint) && isset($hint->index) && is_string($hint->index) && $hint->index !== '') {
            $this->assertIdentifier($hint->index);

            return $hint->index;
        }

        $from = strtolower(str_replace(':', '_', (string) $query->from));
        $name = $from.'_'.$column;
        $this->assertIdentifier($name);

        return $name;
    }

    private function nextParameter(): Parameter
    {
        return Query::parameter($this->nextParameterName());
    }

    private function normalizeOperator(string $operator): string
    {
        $normalized = strtoupper(trim($operator));

        if ($normalized === '!=') {
            $normalized = '<>';
        }

        $allowed = [
            '=', '<>', '<', '<=', '>', '>=',
            'CONTAINS', 'STARTS WITH', 'ENDS WITH', '=~',
        ];

        if (! in_array($normalized, $allowed, true)) {
            throw new InvalidArgumentException("Unsupported operator for Neo4j Query Builder: {$operator}");
        }

        return $normalized;
    }

    private function normalizeComparisonOperator(string $operator): string
    {
        $normalized = trim($operator);

        if ($normalized === '!=') {
            $normalized = '<>';
        }

        $allowed = ['=', '<>', '<', '<=', '>', '>='];

        if (! in_array($normalized, $allowed, true)) {
            throw new InvalidArgumentException("Unsupported comparison operator for Neo4j Query Builder: {$operator}");
        }

        return $normalized;
    }

    private function compileNode(mixed $from): Node
    {
        if (! is_string($from) || $from === '') {
            throw new InvalidArgumentException('Neo4j Query Builder requires a node label via from()/table().');
        }

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?::[A-Za-z_][A-Za-z0-9_]*)*$/', $from)) {
            throw new InvalidArgumentException("Invalid Neo4j label: {$from}");
        }

        return Query::node()
            ->withLabels(explode(':', $from))
            ->withVariable('n');
    }

    private function compileColumn(mixed $column, ?Node $node = null): Property
    {
        if (! is_string($column) || $column === '') {
            throw new InvalidArgumentException('Invalid column reference for Neo4j Query Builder.');
        }

        if (str_contains($column, '.')) {
            [$alias, $property] = explode('.', $column, 2);
            $this->assertIdentifier($alias);
            $this->assertIdentifier($property);

            // Eloquent qualifies keys with the model table/label (for example,
            // User.id), while this single-node grammar always binds that label
            // to the Cypher variable "n".
            return Query::variable('n')->property($property);
        }

        $this->assertIdentifier($column);

        return ($node ?? Query::node()->withVariable('n'))->property($column);
    }

    private function assertIdentifier(string $identifier): void
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException("Invalid Neo4j identifier: {$identifier}");
        }
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function getOperators(): array
    {
        return [
            '=', '<', '>', '<=', '>=', '<>', '!=',
            'contains', 'starts with', 'ends with', '=~',
        ];
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @return string
     */
    #[\Override]
    public function compileInsert(Builder $query, array $values)
    {
        $this->parameterIndex = 0;
        $label = $this->compileLabel($query->from);
        $nodes = [];

        foreach (array_values($values) as $index => $record) {
            $nodes[] = sprintf(
                '(n%d:%s %s)',
                $index,
                $label,
                $this->compilePropertyMap(array_keys($record))
            );
        }

        return 'CREATE '.implode(', ', $nodes);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return string
     */
    #[\Override]
    public function compileUpdate(Builder $query, array $values)
    {
        $this->parameterIndex = 0;
        $node = $this->compileNode($query->from);
        $assignments = [];

        foreach ($values as $column => $value) {
            $property = $this->compileColumn((string) $column, $node)->toQuery();

            if ($this->isExpression($value)) {
                $assignments[] = $property.' = '.$this->getValue($value);
            } else {
                $assignments[] = $property.' = $'.$this->nextParameterName();
            }
        }

        $cypher = Query::new()->match($node);
        $where = $this->compileWhereExpression($query->wheres ?? [], $node);
        $prefix = $cypher->build();

        if ($where !== null) {
            $prefix .= ' '.Query::new()->where($where)->build();
        }

        return $prefix.' SET '.implode(', ', $assignments);
    }

    /**
     * @return string
     */
    #[\Override]
    public function compileDelete(Builder $query)
    {
        $this->parameterIndex = 0;
        $node = $this->compileNode($query->from);
        $cypher = Query::new()->match($node);
        $where = $this->compileWhereExpression($query->wheres ?? [], $node);
        $prefix = $cypher->build();

        if ($where !== null) {
            $prefix .= ' '.Query::new()->where($where)->build();
        }

        return $prefix.' DETACH DELETE n';
    }

    /**
     * @param  list<string>  $columns
     */
    private function compilePropertyMap(array $columns): string
    {
        $properties = [];

        foreach ($columns as $column) {
            $properties[] = $this->propertyName($column).': $'.$this->nextParameterName();
        }

        return '{'.implode(', ', $properties).'}';
    }

    private function compileLabel(mixed $from): string
    {
        if (! is_string($from) || $from === '') {
            throw new InvalidArgumentException('Neo4j Query Builder requires a node label via from()/table().');
        }

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?::[A-Za-z_][A-Za-z0-9_]*)*$/', $from)) {
            throw new InvalidArgumentException("Invalid Neo4j label: {$from}");
        }

        return $from;
    }

    private function nextParameterName(): string
    {
        return 'p'.$this->parameterIndex++;
    }

    private function propertyName(string $column): string
    {
        if (str_contains($column, '.')) {
            [, $property] = explode('.', $column, 2);
            $this->assertIdentifier($property);

            return $property;
        }

        $this->assertIdentifier($column);

        return $column;
    }
}
