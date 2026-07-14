<?php

declare(strict_types=1);

namespace Skrepr\PerformanceMate\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Skrepr\PerformanceMate\Sql;

final class SqlTest extends TestCase
{
    #[DataProvider('provideNormalize')]
    public function testNormalize(string $sql, string $expected): void
    {
        self::assertSame($expected, Sql::normalize($sql));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideNormalize(): iterable
    {
        yield 'whitespace klapt samen' => [
            "SELECT *\n  FROM t\t WHERE a = b",
            'SELECT * FROM t WHERE a = b',
        ];
        yield 'string-literal wordt ?' => [
            "SELECT * FROM t WHERE name = 'jacob'",
            'SELECT * FROM t WHERE name = ?',
        ];
        yield 'escaped quote binnen literal' => [
            "SELECT * FROM t WHERE name = 'it\\'s'",
            'SELECT * FROM t WHERE name = ?',
        ];
        yield 'getallen worden ?' => [
            'SELECT * FROM t WHERE id = 42 AND score > 3.14',
            'SELECT * FROM t WHERE id = ? AND score > ?',
        ];
        yield 'cijfer in identifier blijft staan' => [
            'SELECT col1 FROM t1',
            'SELECT col1 FROM t1',
        ];
        yield 'IN-lijst met waarden klapt samen' => [
            'SELECT * FROM t WHERE id IN (1, 2, 3)',
            'SELECT * FROM t WHERE id IN (?)',
        ];
        yield 'IN-lijst met placeholders klapt samen' => [
            'SELECT * FROM t WHERE id IN (?, ?, ?)',
            'SELECT * FROM t WHERE id IN (?)',
        ];
        yield 'semantisch gelijke queries krijgen dezelfde shape' => [
            "SELECT * FROM users WHERE email = 'a@example.com'   AND age > 30",
            'SELECT * FROM users WHERE email = ? AND age > ?',
        ];
        yield 'doubled-quote escaping binnen literal' => [
            "SELECT * FROM t WHERE name = 'it''s'",
            'SELECT * FROM t WHERE name = ?',
        ];
        yield 'dollar-quoted literal (PostgreSQL)' => [
            'SELECT * FROM t WHERE body = $$tekst; met ' . "'rare'" . ' inhoud$$',
            'SELECT * FROM t WHERE body = ?',
        ];
        yield 'dollar-quoted literal met tag' => [
            'SELECT * FROM t WHERE body = $fn$SELECT 1;$fn$',
            'SELECT * FROM t WHERE body = ?',
        ];
        yield 'double-quoted identifier blijft staan' => [
            'SELECT "name" FROM "users" WHERE id = 7',
            'SELECT "name" FROM "users" WHERE id = ?',
        ];
    }

    #[DataProvider('provideHasMultipleStatements')]
    public function testHasMultipleStatements(string $sql, bool $expected): void
    {
        self::assertSame($expected, Sql::hasMultipleStatements($sql));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideHasMultipleStatements(): iterable
    {
        yield 'één statement' => ['SELECT 1', false];
        yield 'afsluitende puntkomma is ok' => ['SELECT 1;', false];
        yield 'dubbele afsluitende puntkomma is ok' => ['SELECT 1;;  ', false];
        yield 'puntkomma in string-literal is ok' => ["SELECT ';' FROM t WHERE a = 'x;y'", false];
        yield 'puntkomma in escaped literal is ok' => ["SELECT * FROM t WHERE a = 'it\\'s; fine'", false];
        yield 'puntkomma in double-quoted identifier is ok' => ['SELECT "kolom;raar" FROM t', false];
        yield 'puntkomma in backtick-identifier is ok' => ['SELECT `kolom;raar` FROM t', false];
        yield 'puntkomma in regel-comment is ok' => ["SELECT 1 -- ; geen tweede statement\nFROM t", false];
        yield 'puntkomma in blok-comment is ok' => ['SELECT 1 /* ; */ FROM t', false];
        yield 'twee statements' => ['SELECT 1; DELETE FROM users', true];
        yield 'twee statements met afsluiter' => ['SELECT 1; SELECT 2;', true];
        yield 'injectie na literal' => ["SELECT * FROM t WHERE a = 'x'; DROP TABLE t", true];
    }

    public function testTopFrameReturnsFirstProjectFrame(): void
    {
        $frames = [
            ['file' => '/app/src/Repo.php', 'line' => 10, 'call' => 'Repo::find'],
            ['file' => '/app/src/Controller.php', 'line' => 20, 'call' => 'Controller::show'],
        ];

        self::assertSame($frames[0], Sql::topFrame($frames));
        self::assertNull(Sql::topFrame([]));
    }

    public function testFormatFrame(): void
    {
        self::assertSame(
            '/app/src/Repo.php:10 (Repo::find)',
            Sql::formatFrame(['file' => '/app/src/Repo.php', 'line' => 10, 'call' => 'Repo::find']),
        );
        self::assertSame(
            '/app/src/Repo.php:10',
            Sql::formatFrame(['file' => '/app/src/Repo.php', 'line' => 10, 'call' => '']),
        );
    }

    public function testFrameChainFormatsAllFrames(): void
    {
        $frames = [
            ['file' => '/app/src/Repo.php', 'line' => 10, 'call' => 'Repo::find'],
            ['file' => '/app/src/Controller.php', 'line' => 20, 'call' => 'Controller::show'],
        ];

        self::assertSame(
            ['/app/src/Repo.php:10 (Repo::find)', '/app/src/Controller.php:20 (Controller::show)'],
            Sql::frameChain($frames),
        );
    }

    public function testSourceContextReadsSnippetAroundLine(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'mate-test-');
        self::assertNotFalse($file);
        file_put_contents($file, implode("\n", ['regel 1', 'regel 2', 'regel 3', 'regel 4', 'regel 5']));

        try {
            $context = Sql::sourceContext(['file' => $file, 'line' => 3, 'call' => ''], 1);

            self::assertNotNull($context);
            self::assertSame($file, $context['file']);
            self::assertSame(3, $context['line']);
            self::assertSame([
                ['line' => 2, 'code' => 'regel 2'],
                ['line' => 3, 'code' => 'regel 3', 'origin' => true],
                ['line' => 4, 'code' => 'regel 4'],
            ], $context['snippet']);
        } finally {
            unlink($file);
        }
    }

    public function testOriginFieldsWithFrame(): void
    {
        $frame = ['file' => '/app/src/Repo.php', 'line' => 10, 'call' => 'Repo::find'];
        $chain = ['/app/src/Repo.php:10 (Repo::find)', '/app/src/Controller.php:20 (Controller::show)'];

        $fields = Sql::originFields($frame, $chain);

        self::assertSame('/app/src/Repo.php:10 (Repo::find)', $fields['origin']);
        self::assertSame($chain, $fields['origin_chain']);
        // Het frame-bestand bestaat hier niet, dus geen broncontext.
        self::assertNull($fields['origin_context']);
    }

    public function testOriginFieldsChainOfOneAddsNothingBeyondOrigin(): void
    {
        $frame = ['file' => '/app/src/Repo.php', 'line' => 10, 'call' => 'Repo::find'];

        self::assertNull(Sql::originFields($frame, ['/app/src/Repo.php:10 (Repo::find)'])['origin_chain']);
    }

    public function testOriginFieldsWithoutFrameFallsBackToHint(): void
    {
        $fields = Sql::originFields(null, []);

        self::assertStringContainsString('profiling_collect_backtrace', $fields['origin']);
        self::assertNull($fields['origin_chain']);
        self::assertNull($fields['origin_context']);
    }

    public function testSourceContextReturnsNullForMissingFileOrInvalidLine(): void
    {
        self::assertNull(Sql::sourceContext(null));
        self::assertNull(Sql::sourceContext(['file' => '/bestaat/niet.php', 'line' => 1, 'call' => '']));
        self::assertNull(Sql::sourceContext(['file' => __FILE__, 'line' => null, 'call' => '']));
        self::assertNull(Sql::sourceContext(['file' => __FILE__, 'line' => 1000000, 'call' => '']));
    }
}
