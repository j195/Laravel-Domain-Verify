<?php

namespace Tests\Unit;

use App\Support\DomainNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DomainNormalizerTest extends TestCase
{
    #[Test]
    #[DataProvider('validInputs')]
    public function it_normalizes_valid_input(string $input, string $expected): void
    {
        $this->assertSame($expected, DomainNormalizer::fromInput($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function validInputs(): array
    {
        return [
            'email' => ['User@Gmail.com', 'gmail.com'],
            'url' => ['https://mailin.test/path', 'mailin.test'],
            'port' => ['example.com:443', 'example.com'],
            'mailto' => ['mailto:ops@example.com', 'example.com'],
            'ipv4' => ['8.8.8.8', '8.8.8.8'],
        ];
    }

    #[Test]
    #[DataProvider('invalidInputs')]
    public function it_rejects_invalid_input(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        DomainNormalizer::fromInput($input);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidInputs(): array
    {
        return [
            'words' => ['not a domain'],
            'empty' => ['   '],
            'bad email' => ['user@@example.com'],
            'no tld' => ['localhost'],
            'script' => ['<script>alert(1)</script>'],
        ];
    }
}
