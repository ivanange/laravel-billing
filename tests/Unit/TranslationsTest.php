<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Unit;

use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TranslationsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function locales(): iterable
    {
        foreach (glob(__DIR__.'/../../lang/*', GLOB_ONLYDIR) as $dir) {
            if (basename($dir) !== 'en') {
                yield basename($dir) => [basename($dir)];
            }
        }
    }

    #[DataProvider('locales')]
    public function test_every_english_key_exists_in_the_locale(string $locale): void
    {
        $base = __DIR__.'/../../lang/';

        foreach (glob($base.'en/*.php') as $file) {
            $english = array_keys(Arr::dot(require $file));
            $translated = array_keys(Arr::dot(require $base.$locale.'/'.basename($file)));

            $this->assertSame([], array_values(array_diff($english, $translated)), "Missing in {$locale}/".basename($file));
            $this->assertSame([], array_values(array_diff($translated, $english)), "Extra in {$locale}/".basename($file));
        }
    }
}
