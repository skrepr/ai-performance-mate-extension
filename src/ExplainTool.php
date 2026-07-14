<?php

declare(strict_types=1);

namespace Skrepr\SymfonyMate;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Mcp\Capability\Attribute\McpTool;

/**
 * Draait EXPLAIN op een query en zegt WAAROM die traag is (full table scan,
 * ongebruikte index, filesort).
 *
 * Een herbruikbare extensie kent de app-connectie niet, dus de tool bouwt zelf
 * een verbinding op uit een DSN: de parameter skrepr_mate.database_url,
 * met terugval op de DATABASE_URL uit de omgeving (zet mate.env_file in je app).
 */
final class ExplainTool
{
    use JsonResponse;

    private ?Connection $connection = null;

    public function __construct(
        private readonly string $databaseUrl = '',
    ) {
    }

    /**
     * @param string       $sql     Ruwe SQL (placeholders '?' toegestaan; geef dan params mee)
     * @param list<scalar> $params  Positionele params voor de '?'-placeholders
     * @param bool         $analyze EXPLAIN ANALYZE: voert de query echt uit voor echte timings. Alleen SELECT/WITH.
     */
    #[McpTool(
        name: 'explain_query',
        title: 'Explain Query',
        description: 'Draait EXPLAIN op een query en zegt WAAROM die traag is (full table scan, ontbrekende/ongebruikte index, filesort). analyze=true draait EXPLAIN ANALYZE (echte timings) maar is alleen toegestaan voor SELECT/WITH.',
    )]
    public function explainQuery(string $sql, array $params = [], bool $analyze = false): string
    {
        $sql = rtrim(trim($sql), "; \t\n\r");
        if ('' === $sql) {
            return $this->json(['error' => 'explain_query vereist een SQL-string.']);
        }

        // De read-only-check hieronder kijkt alleen naar het eerste statement;
        // een tweede statement ('SELECT 1; DELETE ...') zou bij drivers met
        // emulated prepares gewoon uitgevoerd worden. Hard weigeren dus.
        if (Sql::hasMultipleStatements($sql)) {
            return $this->json(['error' => 'Meerdere SQL-statements gedetecteerd — explain_query accepteert er precies één.']);
        }

        // ANALYZE voert de query echt uit; voor niet-SELECT's zou dat de write
        // uitvoeren. Weigeren en alleen plan-only EXPLAIN toestaan.
        $head = strtoupper(ltrim($sql, " \t\n\r("));
        $isRead = str_starts_with($head, 'SELECT') || str_starts_with($head, 'WITH');
        if ($analyze && !$isRead) {
            return $this->json(['error' => 'ANALYZE geweigerd: query is geen SELECT/WITH — EXPLAIN ANALYZE zou de write echt uitvoeren. Gebruik analyze=false.']);
        }

        try {
            $connection = $this->connection();
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Geen DB-verbinding: '.$e->getMessage()]);
        }

        $platform = $this->detectPlatform($connection);
        $statement = match ($platform) {
            'mysql' => ($analyze ? 'EXPLAIN ANALYZE ' : 'EXPLAIN ').$sql,
            'postgresql' => ($analyze ? 'EXPLAIN (ANALYZE, FORMAT TEXT) ' : 'EXPLAIN ').$sql,
            'sqlite' => 'EXPLAIN QUERY PLAN '.$sql,
            default => 'EXPLAIN '.$sql,
        };

        try {
            $rows = $connection->executeQuery($statement, $params)->fetchAllAssociative();
        } catch (\Throwable $e) {
            return $this->json(['error' => 'EXPLAIN mislukt: '.$e->getMessage().' — klopt het aantal params bij de placeholders?']);
        }

        return $this->json([
            'platform' => $platform,
            'statement' => $statement,
            'analyzed' => $analyze,
            'warnings' => self::warnings($platform, $rows),
            'rows' => $rows,
        ]);
    }

    private function connection(): Connection
    {
        if (null !== $this->connection) {
            return $this->connection;
        }
        $fallback = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
        $url = '' !== $this->databaseUrl
            ? $this->databaseUrl
            : (\is_string($fallback) ? $fallback : '');
        if ('' === $url) {
            throw new \RuntimeException('geen DSN geconfigureerd — zet skrepr_mate.database_url in je mate-config, of DATABASE_URL in de omgeving.');
        }

        // Via DsnParser i.p.v. ['url' => ...]: dat laatste werkt niet meer op DBAL 4.x.
        $params = (new DsnParser([
            'mysql' => 'pdo_mysql',
            'mysql2' => 'pdo_mysql',
            'mariadb' => 'pdo_mysql',
            'pgsql' => 'pdo_pgsql',
            'postgres' => 'pdo_pgsql',
            'postgresql' => 'pdo_pgsql',
            'sqlite' => 'pdo_sqlite',
            'sqlite3' => 'pdo_sqlite',
            'mssql' => 'pdo_sqlsrv',
        ]))->parse($url);

        return $this->connection = DriverManager::getConnection($params);
    }

    private function detectPlatform(Connection $connection): string
    {
        try {
            $pc = strtolower($connection->getDatabasePlatform()::class);
        } catch (\Throwable) {
            return 'other';
        }

        return match (true) {
            str_contains($pc, 'mysql'), str_contains($pc, 'mariadb') => 'mysql',
            str_contains($pc, 'postgre') => 'postgresql',
            str_contains($pc, 'sqlite') => 'sqlite',
            default => 'other',
        };
    }

    /**
     * Platform-bewuste heuristiek op EXPLAIN-output: markeert de klassieke
     * langzaam-signalen zodat de agent geen plan-jargon hoeft te ontleden.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<string>
     *
     * @internal publiek voor tests
     */
    public static function warnings(string $platform, array $rows): array
    {
        $w = [];
        if ('mysql' === $platform) {
            foreach ($rows as $r) {
                $table = (string) ($r['table'] ?? '?');
                if ('ALL' === strtoupper((string) ($r['type'] ?? ''))) {
                    $w[] = "Full table scan op '{$table}' (type=ALL) — vaak een ontbrekende index.";
                }
                $key = $r['key'] ?? null;
                $possibleKeys = $r['possible_keys'] ?? null;
                if ((null === $key || '' === $key) && null !== $possibleKeys && '' !== $possibleKeys) {
                    $w[] = "Index beschikbaar maar niet gebruikt op '{$table}' (possible_keys={$possibleKeys}, key=NULL).";
                }
                $extra = (string) ($r['Extra'] ?? $r['extra'] ?? '');
                if (1 === preg_match('/using filesort/i', $extra)) {
                    $w[] = "Filesort op '{$table}' — overweeg een index die de ORDER BY dekt.";
                }
                if (1 === preg_match('/using temporary/i', $extra)) {
                    $w[] = "Tijdelijke tabel op '{$table}' — vaak door GROUP BY/DISTINCT zonder dekkende index.";
                }
            }
        } elseif ('postgresql' === $platform) {
            foreach ($rows as $r) {
                $line = (string) ($r['QUERY PLAN'] ?? reset($r) ?? '');
                if (1 === preg_match('/Seq Scan on (\S+)/i', $line, $m)) {
                    $w[] = "Sequential scan op '{$m[1]}' — mogelijk ontbrekende index.";
                }
            }
        } elseif ('sqlite' === $platform) {
            foreach ($rows as $r) {
                $detail = trim((string) ($r['detail'] ?? implode(' ', array_map('strval', $r))));
                if (1 === preg_match('/^SCAN\b/i', $detail)) {
                    $w[] = "Full scan: {$detail}";
                }
            }
        }

        return array_values(array_unique($w));
    }
}
