<?php

namespace Neo4j\Neo4jLaravel\Tests\Unit;

use Illuminate\Database\Query\Builder;
use Laudis\Neo4j\Types\CypherList;
use Laudis\Neo4j\Types\CypherMap;
use Laudis\Neo4j\Types\Node;
use Neo4j\Neo4jLaravel\Neo4jProcessor;
use PHPUnit\Framework\TestCase;

final class Neo4jProcessorTest extends TestCase
{
    public function testFlattensMatchedNodePropertiesForEloquentHydration(): void
    {
        $node = new Node(
            1,
            new CypherList(['User']),
            new CypherMap(['id' => 'user-1', 'name' => 'Pratiksha']),
            '4:abc:1'
        );

        $results = (new Neo4jProcessor())->processSelect(
            $this->createMock(Builder::class),
            [new CypherMap(['n' => $node])]
        );

        self::assertSame([
            ['id' => 'user-1', 'name' => 'Pratiksha', 'elementId' => '4:abc:1'],
        ], $results);
    }

    public function testFlattensStdClassRowsFromConnectionSelect(): void
    {
        $node = new Node(
            1,
            new CypherList(['User']),
            new CypherMap(['id' => 'user-1', 'name' => 'Pratiksha']),
            '4:abc:1'
        );

        $results = (new Neo4jProcessor())->processSelect(
            $this->createMock(Builder::class),
            [(object) ['n' => $node]]
        );

        self::assertSame([
            ['id' => 'user-1', 'name' => 'Pratiksha', 'elementId' => '4:abc:1'],
        ], $results);
    }

    public function testExistsColumnIsReadableAfterArrayCast(): void
    {
        $row = (object) ['exists' => true];

        self::assertTrue((bool) ((array) $row)['exists']);
    }

    public function testRemovesNodeAliasFromSelectedPropertyNames(): void
    {
        $results = (new Neo4jProcessor())->processSelect(
            $this->createMock(Builder::class),
            [new CypherMap(['n.name' => 'Pratiksha'])]
        );

        self::assertSame([['name' => 'Pratiksha']], $results);
    }

    public function testKeepsVectorScoreAlongsideNodeProperties(): void
    {
        $node = new Node(
            1,
            new CypherList(['Document']),
            new CypherMap(['id' => 'doc-1', 'title' => 'Graph databases']),
            '4:abc:1'
        );

        $results = (new Neo4jProcessor())->processSelect(
            $this->createMock(Builder::class),
            [new CypherMap(['n' => $node, 'score' => 0.92])]
        );

        self::assertSame([
            ['id' => 'doc-1', 'title' => 'Graph databases', 'elementId' => '4:abc:1', 'score' => 0.92],
        ], $results);
    }
}
