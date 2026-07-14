<?php

declare(strict_types=1);

namespace Skrepr\SymfonyMate\Tests;

use PHPUnit\Framework\TestCase;
use Skrepr\SymfonyMate\JsonResponse;

final class JsonResponseTest extends TestCase
{
    /**
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        $encoder = new class {
            use JsonResponse;

            /**
             * @param array<string, mixed> $data
             */
            public function encode(array $data): string
            {
                return $this->json($data);
            }
        };

        return $encoder->encode($data);
    }

    public function testEncodesCleanDataWithoutWarning(): void
    {
        $json = $this->encode(['a' => 1, 'b' => 'twee']);
        $decoded = json_decode($json, true);

        self::assertSame(['a' => 1, 'b' => 'twee'], $decoded);
    }

    public function testSubstitutesInvalidUtf8(): void
    {
        $json = $this->encode(['naam' => "geldig \xB1\x31 deels"]);
        $decoded = json_decode($json, true);

        self::assertIsArray($decoded);
        self::assertIsString($decoded['naam']);
    }

    public function testAddsEncodingWarningWhenDataDegrades(): void
    {
        $json = $this->encode(['waarde' => \INF]);
        $decoded = json_decode($json, true);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('_encoding_warning', $decoded);
        self::assertSame(0, $decoded['waarde']);
    }

    public function testNoWarningKeyForCleanData(): void
    {
        $decoded = json_decode($this->encode(['a' => 1]), true);

        self::assertIsArray($decoded);
        self::assertArrayNotHasKey('_encoding_warning', $decoded);
    }
}
