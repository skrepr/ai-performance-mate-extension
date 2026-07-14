<?php

declare(strict_types=1);

namespace Skrepr\SymfonyMate;

use Symfony\Bridge\Doctrine\DataCollector\DoctrineDataCollector;
use Symfony\Component\HttpKernel\DataCollector\MemoryDataCollector;
use Symfony\Component\HttpKernel\DataCollector\RequestDataCollector;
use Symfony\Component\HttpKernel\DataCollector\TimeDataCollector;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;
use Symfony\Component\VarDumper\Cloner\Data;

/**
 * Leest Symfony-profielen direct via FileProfilerStorage. Levert per profiel
 * queries (met project-backtrace-frames) en een timeline per categorie — precies
 * wat de analyse-tools nodig hebben en wat de genormaliseerde bridge-output niet geeft.
 *
 * @phpstan-type Frame array{file: string, line: int|null, call: string}
 * @phpstan-type Query array{connection: string, sql: string, ms: float, backtrace: list<Frame>}
 * @phpstan-type StructuredProfile array{
 *     token: string, method: string, url: string, route: ?string, status_code: int,
 *     duration_ms: ?float, memory_mb: ?float, query_count: int, query_time_ms: float,
 *     queries: list<Query>, timeline_ms: array<string, float>,
 * }
 */
final class ProfileReader
{
    /** Boven deze bestandsgrootte (bytes op schijf) slaan we een profiel over i.p.v. het te unserializen. */
    public const DEFAULT_MAX_PROFILE_BYTES = 4 * 1024 * 1024;

    /** Ondergrens memory_limit voor dit proces; zie ensureMemoryHeadroom(). */
    private const MIN_MEMORY_LIMIT_BYTES = 1024 * 1024 * 1024;

    /** Aantal gedistilleerde profielen dat in-process gecachet blijft. */
    private const MAX_CACHED_PROFILES = 20;

    private readonly FileProfilerStorage $storage;

    /**
     * Cache van gedistilleerde profielen. De MCP-server is langlevend en de
     * agent-workflow leest hetzelfde profiel meerdere keren (breakdown → N+1 →
     * diff); unserializen is de duurste operatie hier (±100x bestandsgrootte
     * aan RAM). Profielen zijn immutable zodra geschreven, dus invalidatie is
     * niet nodig. We bewaren het kleine gedistilleerde array, nooit het rauwe
     * Profile-object.
     *
     * @var array<string, StructuredProfile>
     */
    private array $cache = [];

    public function __construct(
        private readonly string $profilerDir,
        private readonly int $maxProfileBytes = self::DEFAULT_MAX_PROFILE_BYTES,
    ) {
        $this->storage = new FileProfilerStorage('file:'.$profilerDir);
        self::ensureMemoryHeadroom();
    }

    /**
     * Recente request-meta's (nieuwste eerst), optioneel gefilterd op URL-substring.
     *
     * @return list<array{token: string, method: string, url: string, status_code: int, time: int}>
     */
    public function findRecent(int $limit, string $urlFilter = ''): array
    {
        $out = [];
        foreach ($this->storage->find(null, '' !== $urlFilter ? $urlFilter : null, $limit, null) as $meta) {
            $out[] = [
                'token' => (string) $meta['token'],
                'method' => (string) $meta['method'],
                'url' => (string) $meta['url'],
                'status_code' => (int) ($meta['status_code'] ?? 0),
                'time' => (int) $meta['time'],
            ];
        }

        return $out;
    }

    /** Token van het recentste request (optioneel gefilterd op URL-substring), of null. */
    public function latestToken(string $urlFilter = ''): ?string
    {
        $metas = $this->findRecent(1, $urlFilter);

        return $metas[0]['token'] ?? null;
    }

    /**
     * Volledig, gestructureerd profiel voor één token, of null als het niet bestaat.
     *
     * @return StructuredProfile|null
     *
     * @throws ProfileTooLargeException wanneer het profielbestand de veilige leeslimiet overschrijdt
     */
    public function read(string $token): ?array
    {
        if (isset($this->cache[$token])) {
            $cached = $this->cache[$token];
            // Herplaatsen naar het einde: recentst gebruikt wordt het laatst verdrongen.
            unset($this->cache[$token]);
            $this->cache[$token] = $cached;

            return $cached;
        }

        $fileFound = $this->guardProfileSize($token);
        $profile = $this->storage->read($token);
        if (null === $profile) {
            return null;
        }

        // Fail-closed: de storage vond een profiel dat profileFilePath() niet kon
        // vinden — dan is Symfony's padschema gewijzigd en staat de size-guard
        // buitenspel. Hard falen zodat dit opgemerkt wordt i.p.v. een stille
        // OOM-blootstelling. (Hercheck vangt de race af waarin het profiel pas
        // ná de guard-check geschreven werd.)
        if (!$fileFound && !is_file($this->profileFilePath($token))) {
            throw new \LogicException(sprintf('Profiel %s is gelezen maar profileFilePath() vond het bestand niet: het padschema van FileProfilerStorage is vermoedelijk gewijzigd. Werk ProfileReader::profileFilePath() bij.', $token));
        }

        $out = [
            'token' => $profile->getToken(),
            'method' => (string) $profile->getMethod(),
            'url' => (string) $profile->getUrl(),
            'route' => null,
            'status_code' => (int) $profile->getStatusCode(),
            'duration_ms' => null,
            'memory_mb' => null,
            'query_count' => 0,
            'query_time_ms' => 0.0,
            'queries' => [],
            'timeline_ms' => [],
        ];

        $time = $profile->hasCollector('time') ? $profile->getCollector('time') : null;
        if ($time instanceof TimeDataCollector) {
            $out['duration_ms'] = round($time->getDuration(), 1);
            $out['timeline_ms'] = $this->timeline($time);
        }
        $memory = $profile->hasCollector('memory') ? $profile->getCollector('memory') : null;
        if ($memory instanceof MemoryDataCollector) {
            $out['memory_mb'] = round($memory->getMemory() / 1048576, 1);
        }
        $request = $profile->hasCollector('request') ? $profile->getCollector('request') : null;
        if ($request instanceof RequestDataCollector) {
            $out['route'] = $request->getRoute();
        }
        $db = $profile->hasCollector('db') ? $profile->getCollector('db') : null;
        if ($db instanceof DoctrineDataCollector) {
            $queries = $this->queries($db);
            $out['queries'] = $queries;
            $out['query_count'] = \count($queries);
            $out['query_time_ms'] = round(array_sum(array_column($queries, 'ms')), 1);
        }

        if (\count($this->cache) >= self::MAX_CACHED_PROFILES) {
            array_shift($this->cache); // minst recent gebruikte staat vooraan
        }
        $this->cache[$token] = $out;

        return $out;
    }

    /**
     * Weigert een profiel dat te groot is om veilig te unserializen. Symfony leest
     * het profiel in één keer in (±100x de bestandsgrootte aan RAM); een uitschieter
     * zou het proces met een niet-vangbare OOM omleggen. Ontbrekende bestanden laten
     * we door: storage->read() geeft daar netjes null op terug.
     *
     * @return bool of het profielbestand op het verwachte pad gevonden is
     *
     * @throws ProfileTooLargeException
     */
    private function guardProfileSize(string $token): bool
    {
        $path = $this->profileFilePath($token);
        if (!is_file($path)) {
            return false;
        }
        $bytes = filesize($path);
        if (false !== $bytes && $bytes > $this->maxProfileBytes) {
            throw new ProfileTooLargeException($token, $bytes, $this->maxProfileBytes);
        }

        return true;
    }

    /** Zelfde padschema als Symfony's FileProfilerStorage::getFilename(). */
    private function profileFilePath(string $token): string
    {
        return $this->profilerDir.'/'.substr($token, -2, 2).'/'.substr($token, -4, 2).'/'.$token;
    }

    /**
     * Verhoogt memory_limit (alleen omhoog) naar een ondergrens. Een profiel wordt in
     * één unserialize()-call ingelezen en kost ±100x de bestandsgrootte aan RAM; de
     * PHP-default van 128M legt het proces al om op één 500-profiel met exception-dump.
     * De harde bovengrens blijft $maxProfileBytes, dat de piek binnen deze grens houdt.
     */
    private static function ensureMemoryHeadroom(): void
    {
        $current = self::parseBytes(\ini_get('memory_limit'));
        if ($current < 0) {
            return; // onbeperkt — niets te doen
        }
        if ($current < self::MIN_MEMORY_LIMIT_BYTES) {
            @ini_set('memory_limit', (string) self::MIN_MEMORY_LIMIT_BYTES);
        }
    }

    /**
     * memory_limit-notatie ('128M', '1G', '-1', bytes) naar bytes; negatief = onbeperkt.
     * Spiegelt bewust PHP's eigen zend_ini_parse_quantity: de integer-parse stopt bij
     * het eerste niet-cijfer, dus '0.5G' is 0 — precies wat PHP er zelf van maakt.
     *
     * @internal publiek voor tests
     */
    public static function parseBytes(string $value): int
    {
        $value = trim($value);
        if ('' === $value) {
            return 0;
        }
        $number = (int) $value;

        return match (strtolower($value[-1])) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * Wall-clock per categorie uit de Stopwatch-events. Categorieen kunnen nesten
     * (bv. 'controller' omvat 'doctrine'/'template'), dus ze tellen niet per se op
     * tot de totale duur.
     *
     * @return array<string, float>
     */
    private function timeline(TimeDataCollector $timeCollector): array
    {
        $byCategory = [];
        foreach ($timeCollector->getEvents() as $event) {
            $cat = $event->getCategory();
            $byCategory[$cat] = ($byCategory[$cat] ?? 0.0) + $event->getDuration();
        }
        arsort($byCategory);

        return array_map(static fn (float $v): float => round($v, 1), $byCategory);
    }

    /**
     * @return list<Query>
     */
    private function queries(DoctrineDataCollector $db): array
    {
        $out = [];
        foreach ($db->getQueries() as $connection => $connQueries) {
            foreach ($connQueries as $q) {
                $out[] = [
                    'connection' => (string) $connection,
                    'sql' => (string) $this->plain($q['sql'] ?? ''),
                    'ms' => round((float) $this->plain($q['executionMS'] ?? 0) * 1000, 2),
                    'backtrace' => $this->frames($q['backtrace'] ?? null),
                ];
            }
        }

        return $out;
    }

    /**
     * Project-frames uit een query-backtrace (vendor eruit, max 6). Alleen aanwezig
     * met doctrine.dbal.profiling_collect_backtrace: true; anders leeg.
     *
     * @return list<Frame>
     */
    private function frames(mixed $bt): array
    {
        if ($bt instanceof Data) {
            $bt = $bt->getValue(true);
        }
        if (!\is_array($bt)) {
            return [];
        }
        $frames = [];
        foreach ($bt as $frame) {
            if (!\is_array($frame)) {
                continue;
            }
            $file = $frame['file'] ?? null;
            if (null === $file || str_contains((string) $file, '/vendor/')) {
                continue;
            }
            $frames[] = [
                'file' => (string) $file,
                'line' => isset($frame['line']) ? (int) $frame['line'] : null,
                'call' => trim(($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '')),
            ];
            if (\count($frames) >= 6) {
                break;
            }
        }

        return $frames;
    }

    /** Symfony VarDumper Data-objecten omzetten naar platte PHP-waarden. */
    private function plain(mixed $value): mixed
    {
        if ($value instanceof Data) {
            try {
                return $value->getValue(true);
            } catch (\Throwable) {
                return (string) $value;
            }
        }

        return $value;
    }

    /**
     * Compacte samenvatting van een gestructureerd profiel voor tool-output.
     *
     * @param StructuredProfile $p
     *
     * @return array<string, mixed>
     */
    public static function summarize(array $p): array
    {
        return [
            'token' => $p['token'],
            'method' => $p['method'],
            'url' => $p['url'],
            'route' => $p['route'],
            'status_code' => $p['status_code'],
            'duration_ms' => $p['duration_ms'],
            'memory_mb' => $p['memory_mb'],
            'query_count' => $p['query_count'],
            'query_time_ms' => $p['query_time_ms'],
        ];
    }
}
