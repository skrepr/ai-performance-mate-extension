<?php

declare(strict_types=1);

namespace Skrepr\PerformanceMate;

/**
 * Pure aggregatielogica over query-lijsten uit profielen: shape-groepering,
 * N+1-detectie en voor/na-diffs. Bewust vrij van IO zodat dit direct testbaar
 * is; de tools mappen de uitkomst alleen nog naar JSON-output.
 *
 * @phpstan-import-type Frame from Sql
 * @phpstan-import-type Query from ProfileReader
 *
 * @phpstan-type ShapeGroup array{total_ms: float, count: int, max_ms: float, sample_sql: string, originFrame: Frame|null, chain: list<string>, seen_on: array<string, true>}
 * @phpstan-type RankedShape array{sql_shape: string, total_ms: float, executions: int, avg_ms: float, max_ms: float, originFrame: Frame|null, chain: list<string>, seen_on: list<string>}
 * @phpstan-type OriginGroup array{shape: string, count: int, total_ms: float, sample_sql: string, originFrame: Frame|null, chain: list<string>, firstIndex: int}
 * @phpstan-type Suspect array{executions: int, total_ms: float, sample_sql: string, sample_sql_truncated?: true, originFrame: Frame|null, chain: list<string>, likely_parent: array{sql_shape: string, sql_shape_truncated?: true, origin: string|null}|null}
 */
final class QueryShapes
{
    /** Maximale lengte van een sql_shape in tool-output; langer krijgt een sql_shape_truncated-signaal. */
    public const MAX_SHAPE_CHARS = 400;

    /** Maximale lengte van sample_sql in tool-output; langer krijgt een sample_sql_truncated-signaal. */
    public const MAX_SAMPLE_SQL_CHARS = 400;

    /**
     * Telt de queries van één request bij in de shape-groepen (voor slow_queries).
     * De origin-frames van de eerste query mét backtrace winnen per groep.
     *
     * @param array<string, ShapeGroup> $groups
     * @param list<Query>               $queries
     *
     * @return array<string, ShapeGroup>
     */
    public static function accumulate(array $groups, array $queries, string $seenOn): array
    {
        foreach ($queries as $q) {
            $key = Sql::normalize($q['sql']);
            $g = $groups[$key] ?? [
                'total_ms' => 0.0, 'count' => 0, 'max_ms' => 0.0,
                'sample_sql' => $q['sql'], 'originFrame' => null, 'chain' => [], 'seen_on' => [],
            ];
            $g['total_ms'] += $q['ms'];
            ++$g['count'];
            $g['max_ms'] = max($g['max_ms'], $q['ms']);
            if (null === $g['originFrame']) {
                $tf = Sql::topFrame($q['backtrace']);
                if (null !== $tf) {
                    $g['originFrame'] = $tf;
                    $g['chain'] = Sql::frameChain($q['backtrace']);
                }
            }
            $g['seen_on'][$seenOn] = true;
            $groups[$key] = $g;
        }

        return $groups;
    }

    /**
     * Traagste shapes eerst (op totale tijd), gemaximeerd op $top, met afgeleide
     * avg_ms en seen_on afgekapt op 5 endpoints.
     *
     * @param array<string, ShapeGroup> $groups
     *
     * @return list<RankedShape>
     */
    public static function rank(array $groups, int $top): array
    {
        uasort($groups, static fn (array $a, array $b): int => $b['total_ms'] <=> $a['total_ms']);

        $out = [];
        foreach (\array_slice($groups, 0, max(1, $top), true) as $shape => $g) {
            $out[] = [
                'sql_shape' => $shape,
                'total_ms' => round($g['total_ms'], 1),
                'executions' => $g['count'],
                'avg_ms' => round($g['total_ms'] / $g['count'], 2),
                'max_ms' => $g['max_ms'],
                'originFrame' => $g['originFrame'],
                'chain' => $g['chain'],
                'seen_on' => \array_slice(array_keys($g['seen_on']), 0, 5),
            ];
        }

        return $out;
    }

    /**
     * Groepeert op (shape + origin): een echte N+1 is dezelfde query die vanuit
     * één regel in een lus herhaald wordt. Zonder backtrace is de origin leeg en
     * valt dit terug op groeperen op shape alleen.
     *
     * @param list<Query> $queries
     *
     * @return array<string, OriginGroup>
     */
    public static function groupByShapeAndOrigin(array $queries): array
    {
        $groups = [];
        foreach ($queries as $i => $q) {
            $shape = Sql::normalize($q['sql']);
            $tf = Sql::topFrame($q['backtrace']);
            $key = $shape.' '.(null !== $tf ? Sql::formatFrame($tf) : '');
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'shape' => $shape, 'count' => 0, 'total_ms' => 0.0, 'sample_sql' => $q['sql'],
                    'originFrame' => $tf, 'chain' => null !== $tf ? Sql::frameChain($q['backtrace']) : [],
                    'firstIndex' => $i,
                ];
            }
            ++$groups[$key]['count'];
            $groups[$key]['total_ms'] += $q['ms'];
        }

        return $groups;
    }

    /**
     * Verdachten (>= $threshold herhalingen), meest herhaald eerst, met de
     * vermoedelijke parent: de query direct vóór de eerste herhaling is vaak
     * degene waarvan het resultaat wordt geïtereerd — de klassieke 1+N-signatuur.
     *
     * @param array<string, OriginGroup> $groups
     * @param list<Query>                $queries
     *
     * @return list<Suspect>
     */
    public static function nPlusOneSuspects(array $groups, array $queries, int $threshold): array
    {
        $suspects = array_filter($groups, static fn (array $g): bool => $g['count'] >= $threshold);
        usort($suspects, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        $out = [];
        foreach ($suspects as $g) {
            $likelyParent = null;
            if ($g['firstIndex'] > 0) {
                $parent = $queries[$g['firstIndex'] - 1];
                $parentShape = Sql::normalize($parent['sql']);
                if ($parentShape !== $g['shape']) {
                    $pf = Sql::topFrame($parent['backtrace']);
                    $likelyParent = [
                        ...self::truncateShape($parentShape),
                        'origin' => null !== $pf ? Sql::formatFrame($pf) : null,
                    ];
                }
            }
            $suspect = [
                'executions' => $g['count'],
                'total_ms' => round($g['total_ms'], 1),
                'sample_sql' => mb_substr($g['sample_sql'], 0, self::MAX_SAMPLE_SQL_CHARS),
                'originFrame' => $g['originFrame'],
                'chain' => $g['chain'],
                'likely_parent' => $likelyParent,
            ];
            if (mb_strlen($g['sample_sql']) > self::MAX_SAMPLE_SQL_CHARS) {
                $suspect['sample_sql_truncated'] = true;
            }
            $out[] = $suspect;
        }

        return $out;
    }

    /**
     * Shape afkappen op MAX_SHAPE_CHARS, mét truncatie-signaal (conform de
     * Mate-designprincipes): de agent moet weten dat hij over een sample
     * redeneert, niet over de volledige query.
     *
     * @return array{sql_shape: string, sql_shape_truncated?: true}
     */
    public static function truncateShape(string $shape): array
    {
        if (mb_strlen($shape) <= self::MAX_SHAPE_CHARS) {
            return ['sql_shape' => $shape];
        }

        return ['sql_shape' => mb_substr($shape, 0, self::MAX_SHAPE_CHARS), 'sql_shape_truncated' => true];
    }

    /**
     * @param list<Query> $queries
     *
     * @return array<string, int> shape => aantal uitvoeringen
     */
    public static function countByShape(array $queries): array
    {
        $m = [];
        foreach ($queries as $q) {
            $shape = Sql::normalize($q['sql']);
            $m[$shape] = ($m[$shape] ?? 0) + 1;
        }

        return $m;
    }

    /**
     * Welke query-shapes zijn verdwenen, bijgekomen of in aantal veranderd
     * tussen twee requests (voor profile_diff).
     *
     * @param array<string, int> $before
     * @param array<string, int> $after
     *
     * @return array{
     *     removed: list<array{sql_shape: string, sql_shape_truncated?: true, executions: int}>,
     *     added: list<array{sql_shape: string, sql_shape_truncated?: true, executions: int}>,
     *     changed: list<array{sql_shape: string, sql_shape_truncated?: true, before: int, after: int}>,
     * }
     */
    public static function diff(array $before, array $after): array
    {
        $removed = [];
        $added = [];
        $changed = [];
        foreach ($before as $shape => $count) {
            if (!isset($after[$shape])) {
                $removed[] = [...self::truncateShape($shape), 'executions' => $count];
            } elseif ($after[$shape] !== $count) {
                $changed[] = [...self::truncateShape($shape), 'before' => $count, 'after' => $after[$shape]];
            }
        }
        foreach ($after as $shape => $count) {
            if (!isset($before[$shape])) {
                $added[] = [...self::truncateShape($shape), 'executions' => $count];
            }
        }

        return ['removed' => $removed, 'added' => $added, 'changed' => $changed];
    }
}
