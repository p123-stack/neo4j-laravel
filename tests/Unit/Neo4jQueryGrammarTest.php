<?php

namespace Neo4j\Neo4jLaravel\Tests\Unit;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\Processor;
use InvalidArgumentException;
use Laudis\Neo4j\Contracts\ClientInterface;
use Neo4j\Neo4jLaravel\Neo4jConnection;
use Neo4j\Neo4jLaravel\Neo4jQueryBuilder;
use Neo4j\Neo4jLaravel\Neo4jQueryGrammar;
use Neo4j\Neo4jLaravel\VectorBinding;
use PHPUnit\Framework\TestCase;

final class Neo4jQueryGrammarTest extends TestCase
{
    public function testCompilesSelectWithDslBackedClauses(): void
    {
        $builder = $this->builder()
            ->from('Person')
            ->where('name', 'Tom Hanks')
            ->whereIn('born', [1956, 1957])
            ->orderBy('name')
            ->offset(10)
            ->limit(5);

        self::assertSame(
            'MATCH (n:Person) WHERE ((n.name = $p0) AND (n.born IN [$p1, $p2])) '
                .'RETURN n ORDER BY n.name ASC SKIP 10 LIMIT 5',
            $builder->toSql()
        );
        self::assertSame(['Tom Hanks', 1956, 1957], $builder->getBindings());
    }

    public function testCompilesNestedWheresNullChecksAndStringOperators(): void
    {
        $builder = $this->builder()
            ->from('Person:Actor')
            ->where(function (Builder $query): void {
                $query->where('name', 'starts with', 'Tom')
                    ->orWhereNull('retired_at');
            })
            ->whereNotBetween('born', [1900, 2000]);

        self::assertSame(
            'MATCH (n:Person:Actor) WHERE (((n.name STARTS WITH $p0) OR (n.retired_at IS NULL)) '
                .'AND (NOT ((n.born >= $p1) AND (n.born <= $p2)))) RETURN n',
            $builder->toSql()
        );
    }

    public function testCompilesSelectedPropertiesDistinctAndMixedOrdering(): void
    {
        $builder = $this->builder()
            ->from('Person')
            ->distinct()
            ->select(['name', 'n.born'])
            ->orderBy('name')
            ->orderByDesc('born');

        self::assertSame(
            'MATCH (n:Person) RETURN DISTINCT n.name, n.born ORDER BY n.name ASC, n.born DESC',
            $builder->toSql()
        );
    }

    public function testCompilesEmptyInClausesWithoutBindings(): void
    {
        $builder = $this->builder()
            ->from('Person')
            ->whereIn('name', []);

        self::assertSame('MATCH (n:Person) WHERE false RETURN n', $builder->toSql());
        self::assertSame([], $builder->getBindings());
    }

    public function testRejectsInvalidLabels(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->builder()->from('Person`) MATCH (x')->toSql();
    }

    public function testMapsEloquentQualifiedColumnsToTheNodeVariable(): void
    {
        $builder = $this->builder()
            ->from('User')
            ->where('User.id', 'user-1');

        self::assertSame(
            'MATCH (n:User) WHERE (n.id = $p0) RETURN n',
            $builder->toSql()
        );
    }

    public function testConnectionMapsPositionalBindingsToCypherParameterNames(): void
    {
        $connection = new Neo4jConnection($this->createMock(ClientInterface::class));

        self::assertSame(
            ['p0' => 'Tom Hanks', 'p1' => 1956],
            $connection->prepareBindings(['Tom Hanks', 1956])
        );
        self::assertSame(
            ['name' => 'Tom Hanks'],
            $connection->prepareBindings(['name' => 'Tom Hanks'])
        );
        self::assertSame(
            ['p0' => [0.1, 0.2], 'p1' => 0.4],
            $connection->prepareBindings([new VectorBinding([0.1, 0.2]), 0.4])
        );
    }

    public function testCompilesSingleAndBatchInserts(): void
    {
        $grammar = new Neo4jQueryGrammar();
        $builder = $this->builder()->from('User');

        self::assertSame(
            'CREATE (n0:User {id: $p0, name: $p1})',
            $grammar->compileInsert($builder, [['id' => 'user-1', 'name' => 'Pratiksha']])
        );
        self::assertSame(
            'CREATE (n0:User {id: $p0, name: $p1}), (n1:User {id: $p2, name: $p3})',
            $grammar->compileInsert($builder, [
                ['id' => 'user-1', 'name' => 'Pratiksha'],
                ['id' => 'user-2', 'name' => 'Ghlen'],
            ])
        );
    }

    public function testCompilesUpdateWithValueBindingsBeforeWhereBindings(): void
    {
        $grammar = new Neo4jQueryGrammar();
        $builder = $this->builder()
            ->from('User')
            ->where('id', 'user-1');

        self::assertSame(
            'MATCH (n:User) WHERE (n.id = $p1) SET n.name = $p0',
            $grammar->compileUpdate($builder, ['name' => 'Pratiksha Zalte'])
        );
        self::assertSame(
            ['Pratiksha Zalte', 'user-1'],
            $grammar->prepareBindingsForUpdate($builder->getRawBindings(), ['Pratiksha Zalte'])
        );
    }

    public function testCompilesUpdateWithEloquentQualifiedTimestampColumns(): void
    {
        $grammar = new Neo4jQueryGrammar();
        $builder = $this->builder()
            ->from('User')
            ->where('User.id', 'user-1');

        self::assertSame(
            'MATCH (n:User) WHERE (n.id = $p2) SET n.name = $p0, n.updated_at = $p1',
            $grammar->compileUpdate($builder, [
                'name' => 'Pratiksha Zalte',
                'User.updated_at' => '2026-08-20 09:56:11',
            ])
        );
    }

    public function testCompilesDelete(): void
    {
        $grammar = new Neo4jQueryGrammar();
        $builder = $this->builder()
            ->from('User')
            ->where('id', 'user-1');

        self::assertSame(
            'MATCH (n:User) WHERE (n.id = $p0) DETACH DELETE n',
            $grammar->compileDelete($builder)
        );
    }

    public function testCompilesVectorSimilaritySearch(): void
    {
        $embedding = [0.1, 0.2, 0.3];

        $builder = $this->vectorBuilder()
            ->from('Document')
            ->whereVectorSimilarTo('embedding', $embedding, minSimilarity: 0.4)
            ->limit(10);

        self::assertSame(
            'CALL db.index.vector.queryNodes(\'document_embedding\', 10, $p0) YIELD node AS n, score '
                .'WHERE score >= $p1 RETURN n, score ORDER BY score DESC LIMIT 10',
            $builder->toSql()
        );
        self::assertSame([$embedding, 0.4], $this->vectorBindings($builder));
    }

    public function testCompilesVectorSimilarityWithAdditionalWheresAndCustomIndex(): void
    {
        $embedding = [0.4, 0.5, 0.6];

        $builder = $this->vectorBuilder()
            ->from('Document')
            ->where('status', 'published')
            ->useVectorIndex('docs_by_embedding')
            ->whereVectorSimilarTo('embedding', $embedding, minSimilarity: 0.8)
            ->limit(5);

        self::assertSame(
            'CALL db.index.vector.queryNodes(\'docs_by_embedding\', 5, $p1) YIELD node AS n, score '
                .'WHERE score >= $p2 AND (n.status = $p0) RETURN n, score ORDER BY score DESC LIMIT 5',
            $builder->toSql()
        );
        self::assertSame(['published', $embedding, 0.8], $this->vectorBindings($builder));
    }

    public function testRejectsNonArrayEmbeddings(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->vectorBuilder()->from('Document')->whereVectorSimilarTo('embedding', 'find similar docs');
    }

    public function testCompilesCountAndExists(): void
    {
        $builder = $this->builder()->from('User')->where('active', true);
        $builder->aggregate = ['function' => 'count', 'columns' => ['*']];

        self::assertSame(
            'MATCH (n:User) WHERE (n.active = $p0) RETURN count(n) AS aggregate',
            $builder->toSql()
        );

        $exists = $this->builder()->from('User')->where('name', 'Pratiksha');
        $grammar = new Neo4jQueryGrammar();

        self::assertSame(
            'MATCH (n:User) WHERE (n.name = $p0) RETURN true AS exists LIMIT 1',
            $grammar->compileExists($exists)
        );
    }

    public function testCompilesAggregatesSumAvgMinMax(): void
    {
        foreach (['sum', 'avg', 'min', 'max'] as $function) {
            $builder = $this->builder()->from('User')->where('active', true);
            $builder->aggregate = ['function' => $function, 'columns' => ['score']];

            self::assertSame(
                "MATCH (n:User) WHERE (n.active = \$p0) RETURN {$function}(n.score) AS aggregate",
                $builder->toSql()
            );
        }
    }

    public function testPassesUnknownAggregatesThroughToCypher(): void
    {
        $builder = $this->builder()->from('User');
        $builder->aggregate = ['function' => 'collect', 'columns' => ['name']];

        self::assertSame(
            'MATCH (n:User) RETURN collect(n.name) AS aggregate',
            $builder->toSql()
        );
    }

    public function testCompilesWhereColumnAndWhereRaw(): void
    {
        $builder = $this->builder()
            ->from('User')
            ->whereColumn('first_name', 'last_name')
            ->whereRaw('n.age > ?', [21]);

        self::assertSame(
            'MATCH (n:User) WHERE (n.first_name = n.last_name AND n.age > $p0) RETURN n',
            $builder->toSql()
        );
        self::assertSame([21], $builder->getBindings());
    }

    public function testCompilesDateWheres(): void
    {
        $builder = $this->builder()
            ->from('User')
            ->whereDate('created_at', '2026-08-31')
            ->whereYear('created_at', 2026);

        self::assertSame(
            "MATCH (n:User) WHERE (date(datetime(replace(toString(n.created_at), ' ', 'T'))) = date(\$p0) "
                ."AND datetime(replace(toString(n.created_at), ' ', 'T')).year = toInteger(\$p1)) RETURN n",
            $builder->toSql()
        );
    }

    public function testCompilesGroupByHaving(): void
    {
        $builder = $this->builder()
            ->from('User')
            ->select('status')
            ->groupBy('status')
            ->having('status', 'active');

        self::assertSame(
            'MATCH (n:User) WITH DISTINCT n.status AS status WHERE status = $p0 RETURN status',
            $builder->toSql()
        );
    }

    public function testCompilesAggregateWithGroupBy(): void
    {
        $builder = $this->builder()->from('User')->groupBy('status');
        $builder->aggregate = ['function' => 'count', 'columns' => ['*']];

        self::assertSame(
            'MATCH (n:User) WITH n.status AS status, count(n) AS aggregate RETURN status, aggregate',
            $builder->toSql()
        );
    }

    public function testCompilesIncrementUpdateExpressions(): void
    {
        $grammar = new Neo4jQueryGrammar();
        $builder = $this->builder()
            ->from('User')
            ->where('id', 'user-1');

        $sql = $grammar->compileUpdate($builder, [
            'votes' => new \Illuminate\Database\Query\Expression('n.votes + 1'),
            'name' => 'Pratiksha',
        ]);

        self::assertSame(
            'MATCH (n:User) WHERE (n.id = $p1) SET n.votes = n.votes + 1, n.name = $p0',
            $sql
        );
    }

    public function testCompilesUnionQueries(): void
    {
        $first = $this->builder()->from('User')->where('role', 'admin')->select('name');
        $second = $this->builder()->from('User')->where('role', 'editor')->select('name');
        $first->union($second);

        self::assertSame(
            'MATCH (n:User) WHERE (n.role = $p0) RETURN n.name '
                .'UNION MATCH (n:User) WHERE (n.role = $p1) RETURN n.name',
            $first->toSql()
        );
        self::assertSame(['admin', 'editor'], $first->getBindings());
    }

    private function vectorBuilder(): Neo4jQueryBuilder
    {
        return new Neo4jQueryBuilder(
            $this->createMock(ConnectionInterface::class),
            new Neo4jQueryGrammar(),
            new Processor()
        );
    }

    /**
     * @return list<mixed>
     */
    private function vectorBindings(Neo4jQueryBuilder $builder): array
    {
        return array_map(
            static fn (mixed $value): mixed => $value instanceof VectorBinding ? $value->values : $value,
            $builder->getBindings()
        );
    }

    private function builder(): Builder
    {
        return new Builder(
            $this->createMock(ConnectionInterface::class),
            new Neo4jQueryGrammar(),
            new Processor()
        );
    }
}
