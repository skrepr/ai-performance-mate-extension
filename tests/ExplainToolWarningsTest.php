<?php

declare(strict_types=1);

namespace Skrepr\SymfonyMate\Tests;

use PHPUnit\Framework\TestCase;
use Skrepr\SymfonyMate\ExplainTool;

final class ExplainToolWarningsTest extends TestCase
{
    public function testMysqlFullTableScan(): void
    {
        $warnings = ExplainTool::warnings('mysql', [
            ['table' => 'users', 'type' => 'ALL'],
        ]);

        self::assertCount(1, $warnings);
        self::assertStringContainsString("Full table scan op 'users'", $warnings[0]);
    }

    public function testMysqlUnusedIndex(): void
    {
        $warnings = ExplainTool::warnings('mysql', [
            ['table' => 'orders', 'type' => 'index', 'possible_keys' => 'idx_user', 'key' => null],
        ]);

        self::assertCount(1, $warnings);
        self::assertStringContainsString("niet gebruikt op 'orders'", $warnings[0]);
    }

    public function testMysqlFilesortAndTemporary(): void
    {
        $warnings = ExplainTool::warnings('mysql', [
            ['table' => 't', 'type' => 'ref', 'key' => 'idx', 'Extra' => 'Using temporary; Using filesort'],
        ]);

        self::assertCount(2, $warnings);
        self::assertStringContainsString('Filesort', $warnings[0]);
        self::assertStringContainsString('Tijdelijke tabel', $warnings[1]);
    }

    public function testMysqlDeduplicatesIdenticalWarnings(): void
    {
        $warnings = ExplainTool::warnings('mysql', [
            ['table' => 'users', 'type' => 'ALL'],
            ['table' => 'users', 'type' => 'ALL'],
        ]);

        self::assertCount(1, $warnings);
    }

    public function testPostgresqlSeqScan(): void
    {
        $warnings = ExplainTool::warnings('postgresql', [
            ['QUERY PLAN' => 'Seq Scan on orders  (cost=0.00..155.00 rows=5000 width=32)'],
        ]);

        self::assertCount(1, $warnings);
        self::assertStringContainsString("Sequential scan op 'orders'", $warnings[0]);
    }

    public function testSqliteFullScan(): void
    {
        $warnings = ExplainTool::warnings('sqlite', [
            ['detail' => 'SCAN users'],
        ]);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('SCAN users', $warnings[0]);
    }

    public function testCleanPlanYieldsNoWarnings(): void
    {
        self::assertSame([], ExplainTool::warnings('mysql', [
            ['table' => 'users', 'type' => 'ref', 'key' => 'PRIMARY', 'Extra' => 'Using index'],
        ]));
        self::assertSame([], ExplainTool::warnings('other', [['whatever' => 'x']]));
    }
}
