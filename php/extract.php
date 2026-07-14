<?php

declare(strict_types=1);

/**
 * Symfony Runtime MCP — profiler extractor.
 *
 * Draait BINNEN de container (waar vendor/ beschikbaar is) en dumpt
 * profiler-data als JSON naar stdout. Wordt aangeroepen door de
 * TypeScript MCP-server via `docker compose exec`.
 *
 * Gebruik:
 *   php extract.php <appDir> list [limit] [urlFilter]
 *   php extract.php <appDir> show <token>
 */

error_reporting(E_ERROR | E_PARSE);

function fail(string $message): never
{
    fwrite(STDOUT, json_encode(['error' => $message], JSON_UNESCAPED_SLASHES));
    exit(1);
}

$appDir = $argv[1] ?? null;
$cmd = $argv[2] ?? null;

if (!$appDir || !$cmd) {
    fail('usage: extract.php <appDir> <list|show> [...]');
}

$autoload = $appDir . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fail("vendor/autoload.php niet gevonden in {$appDir} — klopt het containerpad?");
}
require $autoload;

$profilerDir = $appDir . '/var/cache/dev/profiler';
if (!is_dir($profilerDir)) {
    fail("Geen profiler-map op {$profilerDir}. Draait de app in dev-modus met de profiler aan? Doe eerst een paar requests.");
}

$storage = new \Symfony\Component\HttpKernel\Profiler\FileProfilerStorage('file:' . $profilerDir);

/** Symfony VarDumper Data-objecten omzetten naar platte PHP-waarden. */
function plain(mixed $value): mixed
{
    if ($value instanceof \Symfony\Component\VarDumper\Cloner\Data) {
        try {
            return $value->getValue(true);
        } catch (\Throwable) {
            return (string) $value;
        }
    }
    if (is_array($value)) {
        return array_map('plain', $value);
    }
    if (is_object($value)) {
        return method_exists($value, '__toString') ? (string) $value : get_class($value);
    }

    return $value;
}

/** Gevoelige sleutels redacten voordat data richting agent-context gaat. */
function redact(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    $out = [];
    foreach ($value as $k => $v) {
        if (is_string($k) && preg_match('/pass|secret|token|authorization|api[_-]?key|cookie|csrf/i', $k)) {
            $out[$k] = '[REDACTED]';
        } else {
            $out[$k] = redact($v);
        }
    }

    return $out;
}

function truncate(mixed $value, int $max = 300): mixed
{
    if (is_string($value) && strlen($value) > $max) {
        return substr($value, 0, $max) . '…';
    }
    if (is_array($value)) {
        return array_map(fn ($v) => truncate($v, $max), $value);
    }

    return $value;
}

if ('list' === $cmd) {
    $limit = (int) ($argv[3] ?? 30);
    $urlFilter = $argv[4] ?? '';

    $results = [];
    foreach ($storage->find('', $urlFilter, $limit, '') as $meta) {
        $results[] = [
            'token' => $meta['token'],
            'method' => $meta['method'],
            'url' => $meta['url'],
            'status_code' => (int) ($meta['status_code'] ?? 0),
            'time' => (int) $meta['time'],
            'time_iso' => date('c', (int) $meta['time']),
        ];
    }
    echo json_encode(['requests' => $results], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit(0);
}

if ('show' === $cmd) {
    $token = $argv[3] ?? fail('show vereist een token');
    $profile = $storage->read($token);
    if (!$profile) {
        fail("Geen profiel gevonden voor token {$token}");
    }

    $out = [
        'token' => $profile->getToken(),
        'url' => $profile->getUrl(),
        'method' => $profile->getMethod(),
        'status_code' => $profile->getStatusCode(),
        'time' => $profile->getTime(),
        'time_iso' => date('c', $profile->getTime()),
    ];

    if ($profile->hasCollector('time')) {
        $timeCollector = $profile->getCollector('time');
        $out['duration_ms'] = round($timeCollector->getDuration(), 1);

        // Wall-clock per categorie uit de Stopwatch-events (dezelfde bron als de
        // profiler-timeline). Categorieën als 'doctrine', 'http_client', 'cache'
        // en 'template' laten zien wáár de tijd heen ging — niet alleen in de DB.
        // NB: events kunnen nesten (bv. 'controller' omvat 'doctrine'), dus ze
        // tellen niet per se op tot de totale duur.
        if (method_exists($timeCollector, 'getEvents')) {
            $byCategory = [];
            try {
                foreach ($timeCollector->getEvents() as $event) {
                    if (!is_object($event) || !method_exists($event, 'getDuration') || !method_exists($event, 'getCategory')) {
                        continue;
                    }
                    $cat = (string) $event->getCategory();
                    $byCategory[$cat] = ($byCategory[$cat] ?? 0.0) + (float) $event->getDuration();
                }
            } catch (\Throwable) {
                $byCategory = [];
            }
            if ($byCategory) {
                arsort($byCategory);
                $out['timeline_ms'] = array_map(static fn ($v) => round((float) $v, 1), $byCategory);
            }
        }
    }
    if ($profile->hasCollector('memory')) {
        $out['memory_mb'] = round($profile->getCollector('memory')->getMemory() / 1048576, 1);
    }

    if ($profile->hasCollector('request')) {
        $req = $profile->getCollector('request');
        $out['route'] = plain($req->getRoute());
        $controller = plain($req->getController());
        if (is_array($controller)) {
            $out['controller'] = [
                'class' => $controller['class'] ?? null,
                'method' => $controller['method'] ?? null,
                'file' => $controller['file'] ?? null,
                'line' => $controller['line'] ?? null,
            ];
        } else {
            $out['controller'] = $controller;
        }
        $out['request_query'] = truncate(redact(plain($req->getRequestQuery()->all())));
        $out['request_post'] = truncate(redact(plain($req->getRequestRequest()->all())));
    }

    if ($profile->hasCollector('db')) {
        $db = $profile->getCollector('db');
        $queries = [];
        foreach ($db->getQueries() as $connection => $connQueries) {
            foreach ($connQueries as $q) {
                $entry = [
                    'connection' => $connection,
                    'sql' => (string) plain($q['sql'] ?? ''),
                    'params' => truncate(redact(plain($q['params'] ?? [])), 120),
                    'ms' => round((float) plain($q['executionMS'] ?? 0) * 1000, 2),
                ];
                // Alleen aanwezig met doctrine.dbal.profiling_collect_backtrace: true.
                // We bewaren de hele project-frame-keten (vendor eruit i.p.v. de
                // eerste 8 frames vooraf af te kappen), zodat de MCP-server bij
                // writes de weg náár flush() kan tonen — de flush()-aanroeper zit
                // vaak dieper dan 8 Doctrine-internals-frames.
                $bt = $q['backtrace'] ?? null;
                if ($bt instanceof \Symfony\Component\VarDumper\Cloner\Data) {
                    $bt = $bt->getValue(true);
                }
                if (is_array($bt) && $bt) {
                    $frames = [];
                    foreach ($bt as $frame) {
                        if (!is_array($frame)) {
                            continue;
                        }
                        $file = $frame['file'] ?? null;
                        // frames zonder bestand (interne/closure calls) en
                        // vendor-frames (Doctrine internals) overslaan
                        if (null === $file || str_contains((string) $file, '/vendor/')) {
                            continue;
                        }
                        $frames[] = [
                            'file' => $file,
                            'line' => $frame['line'] ?? null,
                            'call' => trim(($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '')),
                        ];
                        if (count($frames) >= 6) {
                            break;
                        }
                    }
                    if ($frames) {
                        $entry['backtrace'] = $frames;
                    }
                }
                $queries[] = $entry;
            }
        }
        $out['query_count'] = count($queries);
        $out['query_time_ms'] = round(array_sum(array_column($queries, 'ms')), 1);
        $out['queries'] = $queries;
    }

    if ($profile->hasCollector('logger')) {
        $logger = $profile->getCollector('logger');
        $out['log_error_count'] = $logger->countErrors();
        $out['log_deprecation_count'] = method_exists($logger, 'countDeprecations') ? $logger->countDeprecations() : null;
    }

    echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit(0);
}

if ('explain' === $cmd) {
    $sql = base64_decode((string) ($argv[3] ?? ''), true);
    $paramsJson = base64_decode((string) ($argv[4] ?? 'W10='), true); // W10= == "[]"
    $analyze = '1' === ($argv[5] ?? '0');

    if (false === $sql || '' === trim((string) $sql)) {
        fail('explain vereist een (base64) SQL-string');
    }
    $params = json_decode(false === $paramsJson ? '[]' : $paramsJson, true);
    if (!is_array($params)) {
        $params = [];
    }

    // ANALYZE vóért de query daadwerkelijk uit. Voor niet-SELECT's zou dat de
    // write echt uitvoeren — dus weigeren en alleen plan-only EXPLAIN toestaan.
    $head = strtoupper(ltrim((string) $sql, " \t\n\r(("));
    $isRead = str_starts_with($head, 'SELECT') || str_starts_with($head, 'WITH');
    if ($analyze && !$isRead) {
        fail('ANALYZE geweigerd: query is geen SELECT/WITH — EXPLAIN ANALYZE zou de write echt uitvoeren. Gebruik analyze=false voor een plan-only EXPLAIN.');
    }

    if (!class_exists(\Doctrine\DBAL\Tools\DsnParser::class)) {
        fail('DBAL DsnParser niet beschikbaar — explain_query vereist doctrine/dbal >= 3.2.');
    }

    $url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? (getenv('DATABASE_URL') ?: null);
    if (!$url && class_exists(\Symfony\Component\Dotenv\Dotenv::class) && is_file($appDir . '/.env')) {
        try {
            (new \Symfony\Component\Dotenv\Dotenv())->loadEnv($appDir . '/.env');
        } catch (\Throwable) {
            // best effort — val terug op de fout hieronder
        }
        $url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? (getenv('DATABASE_URL') ?: null);
    }
    if (!$url) {
        fail('Geen DATABASE_URL gevonden in de omgeving of .env — explain_query maakt daarmee verbinding.');
    }

    $parser = new \Doctrine\DBAL\Tools\DsnParser([
        'mysql' => 'pdo_mysql',
        'mysql2' => 'pdo_mysql',
        'mariadb' => 'pdo_mysql',
        'postgres' => 'pdo_pgsql',
        'postgresql' => 'pdo_pgsql',
        'pgsql' => 'pdo_pgsql',
        'sqlite' => 'pdo_sqlite',
        'sqlite3' => 'pdo_sqlite',
        'mssql' => 'pdo_sqlsrv',
    ]);

    try {
        $conn = \Doctrine\DBAL\DriverManager::getConnection($parser->parse((string) $url));
    } catch (\Throwable $e) {
        fail('Kon geen DB-verbinding opzetten uit DATABASE_URL: ' . $e->getMessage());
    }

    $platformName = 'other';
    try {
        $pc = strtolower(get_class($conn->getDatabasePlatform()));
        if (str_contains($pc, 'mysql') || str_contains($pc, 'mariadb')) {
            $platformName = 'mysql';
        } elseif (str_contains($pc, 'postgre')) {
            $platformName = 'postgresql';
        } elseif (str_contains($pc, 'sqlite')) {
            $platformName = 'sqlite';
        } elseif (str_contains($pc, 'sqlserver') || str_contains($pc, 'sqlsrv')) {
            $platformName = 'sqlserver';
        }
    } catch (\Throwable) {
        // platformdetectie faalt bij een dode DB — de executeQuery hieronder
        // geeft dan de echte fout terug
    }

    $stmt = match ($platformName) {
        'mysql' => ($analyze ? 'EXPLAIN ANALYZE ' : 'EXPLAIN ') . $sql,
        'postgresql' => ($analyze ? 'EXPLAIN (ANALYZE, FORMAT TEXT) ' : 'EXPLAIN ') . $sql,
        'sqlite' => 'EXPLAIN QUERY PLAN ' . $sql,
        default => 'EXPLAIN ' . $sql,
    };

    try {
        $rows = $conn->executeQuery($stmt, $params)->fetchAllAssociative();
    } catch (\Throwable $e) {
        fail('EXPLAIN mislukt: ' . $e->getMessage() . ' — klopt het aantal params bij de placeholders?');
    }

    echo json_encode(
        ['platform' => $platformName, 'statement' => $stmt, 'analyzed' => $analyze, 'rows' => $rows],
        JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
    );
    exit(0);
}

fail("Onbekend commando: {$cmd}");
