<?php

declare(strict_types=1);

namespace Skrepr\PerformanceMate;

/**
 * Gedeelde token-resolutie voor tools die één profiel lezen: leeg token =
 * recentste request (optioneel via URL-filter), met alle foutpaden als
 * kant-en-klare JSON-foutmelding voor de agent.
 *
 * Verwacht dat de gebruikende klasse een `private readonly ProfileReader $reader`
 * heeft (alle tools krijgen die via constructor promotion).
 *
 * @phpstan-import-type StructuredProfile from ProfileReader
 */
trait ResolvesProfile
{
    use JsonResponse;

    /**
     * @return StructuredProfile|string het profiel, of een JSON-foutmelding wanneer het niet gelezen kon worden
     */
    private function resolveProfile(?string $token, ?string $urlFilter): array|string
    {
        if (null === $token || '' === $token) {
            $token = $this->reader->latestToken($urlFilter ?? '');
        }
        if (null === $token) {
            return $this->json(['error' => 'Geen requests gevonden — doe eerst een request naar de app.']);
        }
        try {
            $profile = $this->reader->read($token);
        } catch (ProfileTooLargeException $e) {
            return $this->json(['error' => $e->getMessage()]);
        }
        if (null === $profile) {
            return $this->json(['error' => "Geen profiel gevonden voor token {$token}."]);
        }

        return $profile;
    }
}
