<?php

declare(strict_types=1);

namespace Skrepr\SymfonyMate;

trait JsonResponse
{
    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data): string
    {
        $flags = \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR;
        $json = json_encode($data, $flags);

        // JSON_PARTIAL_OUTPUT_ON_ERROR laat encoding stilletjes degraderen
        // (waardes vervangen of weggelaten); maak dat zichtbaar voor de agent.
        if (\JSON_ERROR_NONE !== json_last_error()) {
            $data['_encoding_warning'] = 'Een deel van de data kon niet als JSON gecodeerd worden en is vervangen of weggelaten ('.json_last_error_msg().').';
            $json = json_encode($data, $flags);
        }

        return false !== $json ? $json : '{"_encoding_warning": "json_encode faalde volledig"}';
    }
}
