<?php

declare(strict_types=1);

namespace Skrepr\SymfonyMate\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Skrepr\SymfonyMate\ProfileReader;
use Skrepr\SymfonyMate\ProfileTooLargeException;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;
use Symfony\Component\HttpKernel\Profiler\Profile;

final class ProfileReaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir().'/mate-profiler-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->dir = $dir;
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $item) {
            \assert($item instanceof \SplFileInfo);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dir);
    }

    #[DataProvider('provideParseBytes')]
    public function testParseBytes(string $value, int $expected): void
    {
        self::assertSame($expected, ProfileReader::parseBytes($value));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideParseBytes(): iterable
    {
        yield 'megabytes' => ['128M', 128 * 1024 * 1024];
        yield 'gigabytes' => ['1G', 1024 * 1024 * 1024];
        yield 'kilobytes kleine letter' => ['512k', 512 * 1024];
        yield 'kale bytes' => ['134217728', 134217728];
        yield 'onbeperkt' => ['-1', -1];
        yield 'lege string' => ['', 0];
        yield 'met spaties' => [' 256M ', 256 * 1024 * 1024];
        // PHP's zend_ini_parse_quantity stopt de integer-parse bij de punt en maakt
        // van '0.5G' dus 0; parseBytes spiegelt dat gedrag bewust.
        yield 'fractioneel volgt PHP-semantiek' => ['0.5G', 0];
    }

    /**
     * Pint het padschema van profileFilePath() vast tegen een écht door
     * FileProfilerStorage geschreven profiel: met maxProfileBytes=1 mag read()
     * alleen ProfileTooLargeException gooien als de guard het bestand VINDT.
     * Wijzigt Symfony het schema, dan faalt deze test — dat is het signaal om
     * ProfileReader::profileFilePath() bij te werken.
     */
    public function testGuardFindsTheFileWrittenByFileProfilerStorage(): void
    {
        $this->writeProfile('abcdef');

        $reader = new ProfileReader($this->dir, maxProfileBytes: 1);

        $this->expectException(ProfileTooLargeException::class);
        $reader->read('abcdef');
    }

    public function testReadReturnsStructuredProfile(): void
    {
        $this->writeProfile('abcdef');

        $profile = (new ProfileReader($this->dir))->read('abcdef');

        self::assertNotNull($profile);
        self::assertSame('abcdef', $profile['token']);
        self::assertSame('GET', $profile['method']);
        self::assertSame('http://localhost/test', $profile['url']);
        self::assertSame(200, $profile['status_code']);
        self::assertSame(0, $profile['query_count']);
        self::assertSame([], $profile['queries']);
    }

    public function testReadCachesTheDistilledProfile(): void
    {
        $this->writeProfile('abcdef');
        $reader = new ProfileReader($this->dir);

        $first = $reader->read('abcdef');
        self::assertNotNull($first);

        // Verwijder alles op schijf: een tweede read kan alleen nog slagen
        // als hij uit de in-process cache komt.
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $item) {
            \assert($item instanceof \SplFileInfo);
            if ($item->isFile()) {
                unlink($item->getPathname());
            }
        }

        self::assertSame($first, $reader->read('abcdef'));
        // Een verse reader zonder cache vindt het profiel niet meer.
        self::assertNull((new ProfileReader($this->dir))->read('abcdef'));
    }

    public function testReadReturnsNullForUnknownToken(): void
    {
        self::assertNull((new ProfileReader($this->dir))->read('bestaatniet'));
    }

    public function testFindRecentAndLatestToken(): void
    {
        $this->writeProfile('token1', 'http://localhost/a');
        $this->writeProfile('token2', 'http://localhost/b');

        $reader = new ProfileReader($this->dir);

        $recent = $reader->findRecent(10);
        self::assertCount(2, $recent);

        self::assertSame('token2', $reader->latestToken('/b'));
        self::assertNull($reader->latestToken('/bestaat-niet'));
    }

    private function writeProfile(string $token, string $url = 'http://localhost/test'): void
    {
        $profile = new Profile($token);
        $profile->setMethod('GET');
        $profile->setUrl($url);
        $profile->setStatusCode(200);
        $profile->setTime(time());

        $storage = new FileProfilerStorage('file:'.$this->dir);
        self::assertTrue($storage->write($profile));
    }
}
