<?php

declare(strict_types=1);

namespace Skrepr\SymfonyMate;

use Mcp\Capability\Attribute\McpTool;

final class ProfileDiffTool
{
    use JsonResponse;

    public function __construct(
        private readonly ProfileReader $reader,
    ) {
    }

    /**
     * @param string|null $tokenBefore Token van het 'voor'-profiel; leeg = op één na recentste van urlFilter
     * @param string|null $tokenAfter  Token van het 'na'-profiel; leeg = recentste van urlFilter
     * @param string|null $urlFilter   Substring-filter op de URL om automatisch de twee recentste te vergelijken
     */
    #[McpTool(
        name: 'profile_diff',
        title: 'Profile Diff',
        description: 'Vergelijkt twee profielen van hetzelfde endpoint (voor/na een codewijziging): duur, geheugen, query-count en welke query-shapes zijn verdwenen of bijgekomen. Geef twee tokens, of een urlFilter om automatisch de twee recentste requests te vergelijken.',
    )]
    public function profileDiff(?string $tokenBefore = null, ?string $tokenAfter = null, ?string $urlFilter = null): string
    {
        $before = $tokenBefore;
        $after = $tokenAfter;
        if (null === $before || '' === $before || null === $after || '' === $after) {
            $metas = $this->reader->findRecent(2, $urlFilter ?? '');
            if (\count($metas) < 2) {
                return $this->json(['error' => 'Minstens twee requests naar dit endpoint nodig om te vergelijken.']);
            }
            $after ??= $metas[0]['token'];   // recentst
            $before ??= $metas[1]['token'];  // een eerder
        }

        try {
            $pBefore = $this->reader->read($before);
            $pAfter = $this->reader->read($after);
        } catch (ProfileTooLargeException $e) {
            return $this->json(['error' => $e->getMessage()]);
        }
        if (null === $pBefore || null === $pAfter) {
            return $this->json(['error' => 'Eén van beide profielen kon niet gelezen worden.']);
        }

        $diff = QueryShapes::diff(
            QueryShapes::countByShape($pBefore['queries']),
            QueryShapes::countByShape($pAfter['queries']),
        );

        return $this->json([
            'before' => ProfileReader::summarize($pBefore),
            'after' => ProfileReader::summarize($pAfter),
            'delta' => [
                'duration_ms' => $this->delta($pBefore['duration_ms'], $pAfter['duration_ms']),
                'memory_mb' => $this->delta($pBefore['memory_mb'], $pAfter['memory_mb']),
                'query_count' => $this->delta((float) $pBefore['query_count'], (float) $pAfter['query_count']),
                'query_time_ms' => $this->delta($pBefore['query_time_ms'], $pAfter['query_time_ms']),
            ],
            'queries_removed' => $diff['removed'],
            'queries_added' => $diff['added'],
            'queries_changed' => $diff['changed'],
        ]);
    }

    private function delta(?float $a, ?float $b): ?float
    {
        return null !== $a && null !== $b ? round($b - $a, 1) : null;
    }
}
