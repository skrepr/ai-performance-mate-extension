<?php

declare(strict_types=1);

namespace Skrepr\SymfonyMate;

/**
 * Gegooid wanneer een profielbestand groter is dan de veilige leeslimiet.
 *
 * Symfony unserializet een profiel in één keer en dat kost ±100x de
 * bestandsgrootte aan RAM (een 500 met volledige exception-dump is ~1 MB op
 * schijf en ~100 MB in geheugen). Eén uitschieter zou het serverproces anders
 * met een fatale, niet-vangbare OOM omleggen. Zie ProfileReader::$maxProfileBytes.
 */
final class ProfileTooLargeException extends \RuntimeException
{
    public function __construct(
        public readonly string $token,
        public readonly int $bytes,
        public readonly int $maxBytes,
    ) {
        parent::__construct(sprintf(
            'Profiel %s is %d bytes op schijf en overschrijdt de veilige leeslimiet van %d bytes; overgeslagen om een out-of-memory te voorkomen. Verhoog skrepr_mate.max_profile_bytes als je dit profiel toch wilt analyseren.',
            $token,
            $bytes,
            $maxBytes,
        ));
    }
}
