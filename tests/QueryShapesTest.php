<?php

declare(strict_types=1);

namespace Skrepr\PerformanceMate\Tests;

use PHPUnit\Framework\TestCase;
use Skrepr\PerformanceMate\QueryShapes;

final class QueryShapesTest extends TestCase
{
    /**
     * @param list<array{file: string, line: int|null, call: string}> $backtrace
     *
     * @return array{connection: string, sql: string, ms: float, backtrace: list<array{file: string, line: int|null, call: string}>}
     */
    private static function query(string $sql, float $ms, array $backtrace = []): array
    {
        return ['connection' => 'default', 'sql' => $sql, 'ms' => $ms, 'backtrace' => $backtrace];
    }

    public function testAccumulateGroupsAcrossRequestsAndTracksTotals(): void
    {
        $frame = ['file' => '/app/src/Repo.php', 'line' => 10, 'call' => 'Repo::find'];

        $groups = QueryShapes::accumulate([], [
            self::query('SELECT * FROM t WHERE id = 1', 5.0),
            self::query('SELECT * FROM t WHERE id = 2', 15.0, [$frame]),
        ], 'GET /a');
        $groups = QueryShapes::accumulate($groups, [
            self::query('SELECT * FROM t WHERE id = 3', 10.0),
        ], 'GET /b');

        self::assertCount(1, $groups);
        $g = $groups['SELECT * FROM t WHERE id = ?'];
        self::assertSame(30.0, $g['total_ms']);
        self::assertSame(3, $g['count']);
        self::assertSame(15.0, $g['max_ms']);
        // De eerste query mét backtrace levert de origin, ook al was hij niet de eerste van de groep.
        self::assertSame($frame, $g['originFrame']);
        self::assertSame(['GET /a' => true, 'GET /b' => true], $g['seen_on']);
        // De sample is de eerste ruwe SQL van de groep.
        self::assertSame('SELECT * FROM t WHERE id = 1', $g['sample_sql']);
    }

    public function testRankSortsByTotalTimeAndComputesAverages(): void
    {
        $groups = QueryShapes::accumulate([], [
            self::query('SELECT a FROM snel WHERE id = 1', 1.0),
            self::query('SELECT b FROM traag WHERE id = 1', 50.0),
            self::query('SELECT b FROM traag WHERE id = 2', 30.0),
        ], 'GET /x');

        $ranked = QueryShapes::rank($groups, 10);

        self::assertCount(2, $ranked);
        self::assertSame('SELECT b FROM traag WHERE id = ?', $ranked[0]['sql_shape']);
        self::assertSame(80.0, $ranked[0]['total_ms']);
        self::assertSame(2, $ranked[0]['executions']);
        self::assertSame(40.0, $ranked[0]['avg_ms']);
        self::assertSame(50.0, $ranked[0]['max_ms']);
        self::assertSame('SELECT a FROM snel WHERE id = ?', $ranked[1]['sql_shape']);
    }

    public function testRankCapsTopAndSeenOn(): void
    {
        $groups = [];
        foreach (range(1, 4) as $i) {
            $groups = QueryShapes::accumulate($groups, [
                self::query("SELECT c{$i} FROM t{$i}", (float) $i),
            ], 'GET /x');
        }
        $ranked = QueryShapes::rank($groups, 2);
        self::assertCount(2, $ranked);

        $manyEndpoints = [];
        foreach (range(1, 7) as $i) {
            $manyEndpoints = QueryShapes::accumulate($manyEndpoints, [
                self::query('SELECT x FROM t', 1.0),
            ], "GET /pagina-{$i}");
        }
        self::assertCount(5, QueryShapes::rank($manyEndpoints, 1)[0]['seen_on']);
    }

    public function testGroupByShapeAndOriginSeparatesSameShapeFromDifferentOrigins(): void
    {
        $frameA = ['file' => '/app/src/A.php', 'line' => 1, 'call' => 'A::run'];
        $frameB = ['file' => '/app/src/B.php', 'line' => 2, 'call' => 'B::run'];

        $groups = QueryShapes::groupByShapeAndOrigin([
            self::query('SELECT * FROM t WHERE id = 1', 1.0, [$frameA]),
            self::query('SELECT * FROM t WHERE id = 2', 1.0, [$frameA]),
            self::query('SELECT * FROM t WHERE id = 3', 1.0, [$frameB]),
        ]);

        self::assertCount(2, $groups);
        $counts = array_column(array_values($groups), 'count');
        sort($counts);
        self::assertSame([1, 2], $counts);
    }

    public function testNPlusOneSuspectsFiltersOnThresholdAndSortsByCount(): void
    {
        $queries = [
            self::query('SELECT * FROM parent', 2.0),
            self::query('SELECT * FROM child WHERE parent_id = 1', 1.0),
            self::query('SELECT * FROM child WHERE parent_id = 2', 1.0),
            self::query('SELECT * FROM child WHERE parent_id = 3', 1.0),
        ];
        $groups = QueryShapes::groupByShapeAndOrigin($queries);

        self::assertCount(0, QueryShapes::nPlusOneSuspects($groups, $queries, 4));

        $suspects = QueryShapes::nPlusOneSuspects($groups, $queries, 3);
        self::assertCount(1, $suspects);
        self::assertSame(3, $suspects[0]['executions']);
        self::assertSame(3.0, $suspects[0]['total_ms']);
        // De query direct vóór de eerste herhaling is de vermoedelijke parent.
        self::assertNotNull($suspects[0]['likely_parent']);
        self::assertSame('SELECT * FROM parent', $suspects[0]['likely_parent']['sql_shape']);
    }

    public function testNPlusOneSuspectsHasNoParentWhenRepetitionStartsAtIndexZero(): void
    {
        $queries = [
            self::query('SELECT * FROM child WHERE parent_id = 1', 1.0),
            self::query('SELECT * FROM child WHERE parent_id = 2', 1.0),
        ];

        $suspects = QueryShapes::nPlusOneSuspects(QueryShapes::groupByShapeAndOrigin($queries), $queries, 2);

        self::assertCount(1, $suspects);
        self::assertNull($suspects[0]['likely_parent']);
    }

    public function testNPlusOneSuspectsIgnoresParentWithSameShape(): void
    {
        // Als de 'parent' dezelfde shape heeft, is het geen parent maar dezelfde lus.
        $queries = [
            self::query('SELECT * FROM child WHERE parent_id = 99', 1.0, [
                ['file' => '/app/src/Other.php', 'line' => 5, 'call' => 'Other::x'],
            ]),
            self::query('SELECT * FROM child WHERE parent_id = 1', 1.0),
            self::query('SELECT * FROM child WHERE parent_id = 2', 1.0),
        ];

        $suspects = QueryShapes::nPlusOneSuspects(QueryShapes::groupByShapeAndOrigin($queries), $queries, 2);

        self::assertNull($suspects[0]['likely_parent']);
    }

    public function testCountByShapeAndDiff(): void
    {
        $before = QueryShapes::countByShape([
            self::query('SELECT * FROM a WHERE id = 1', 1.0),
            self::query('SELECT * FROM a WHERE id = 2', 1.0),
            self::query('SELECT * FROM weg WHERE id = 1', 1.0),
        ]);
        $after = QueryShapes::countByShape([
            self::query('SELECT * FROM a WHERE id = 5', 1.0),
            self::query('SELECT * FROM nieuw WHERE id = 1', 1.0),
        ]);

        $diff = QueryShapes::diff($before, $after);

        self::assertSame([['sql_shape' => 'SELECT * FROM weg WHERE id = ?', 'executions' => 1]], $diff['removed']);
        self::assertSame([['sql_shape' => 'SELECT * FROM nieuw WHERE id = ?', 'executions' => 1]], $diff['added']);
        self::assertSame([['sql_shape' => 'SELECT * FROM a WHERE id = ?', 'before' => 2, 'after' => 1]], $diff['changed']);
    }

    public function testDiffOfIdenticalProfilesIsEmpty(): void
    {
        $shapes = QueryShapes::countByShape([self::query('SELECT 1 FROM t', 1.0)]);

        self::assertSame(['removed' => [], 'added' => [], 'changed' => []], QueryShapes::diff($shapes, $shapes));
    }

    public function testTruncateShapeSignalsTruncation(): void
    {
        // Korte shapes blijven ongemoeid, zonder signaal.
        self::assertSame(['sql_shape' => 'SELECT 1 FROM t'], QueryShapes::truncateShape('SELECT 1 FROM t'));

        $long = 'SELECT '.str_repeat('kolom, ', 100).'x FROM t';
        $truncated = QueryShapes::truncateShape($long);
        self::assertSame(mb_substr($long, 0, QueryShapes::MAX_SHAPE_CHARS), $truncated['sql_shape']);
        self::assertTrue($truncated['sql_shape_truncated'] ?? false);
    }

    public function testDiffTruncatesLongShapesWithSignal(): void
    {
        $long = 'SELECT '.str_repeat('a', 500);

        $diff = QueryShapes::diff([$long => 1], [$long => 2, 'SELECT 1 FROM nieuw' => 1]);

        self::assertSame(mb_substr($long, 0, QueryShapes::MAX_SHAPE_CHARS), $diff['changed'][0]['sql_shape']);
        self::assertTrue($diff['changed'][0]['sql_shape_truncated'] ?? false);
        // Niet-getrunceerde shapes krijgen géén signaal.
        self::assertArrayNotHasKey('sql_shape_truncated', $diff['added'][0]);
    }

    public function testNPlusOneSuspectsTruncateSampleSqlWithSignal(): void
    {
        $long = 'SELECT * FROM child WHERE blob = '.str_repeat('9', 500);
        $queries = [self::query($long, 1.0), self::query($long, 1.0)];

        $suspects = QueryShapes::nPlusOneSuspects(QueryShapes::groupByShapeAndOrigin($queries), $queries, 2);

        self::assertCount(1, $suspects);
        self::assertSame(mb_substr($long, 0, QueryShapes::MAX_SAMPLE_SQL_CHARS), $suspects[0]['sample_sql']);
        self::assertTrue($suspects[0]['sample_sql_truncated'] ?? false);
    }
}
