<?php
declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Application\Agents\JobCdlAssembler;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

final class JobCdlAssemblerTest extends TestCase
{
    private static function xpath(string $xml): DOMXPath
    {
        $document = new DOMDocument();
        self::assertTrue($document->loadXML($xml));
        return new DOMXPath($document);
    }

    public function testExplicitSetupAndGeneralSettingsKeepJobParameterPrecedence(): void
    {
        $parameters = ['workers' => 0, 'mode' => 'false'];
        $general = ['workers' => 9, 'storage' => 'persistent'];
        $setup = ['workers' => 4, 'prepare' => 'yes'];
        $before = [$parameters, $general, $setup];
        $xml = JobCdlAssembler::build(7, $parameters, $general, $setup);
        $xpath = self::xpath($xml);
        self::assertSame('7', $xpath->evaluate('string(/chronos/evaluation/@system)'));
        self::assertSame('4', $xpath->evaluate('string(/chronos/setup/workers)'));
        self::assertSame('0', $xpath->evaluate('string(/chronos/evaluation/workers)'));
        self::assertSame('false', $xpath->evaluate('string(/chronos/evaluation/mode)'));
        self::assertSame('persistent', $xpath->evaluate('string(/chronos/evaluation/storage)'));
        self::assertSame(0.0, $xpath->evaluate('count(/chronos/evaluation/prepare | /chronos/setup/storage)'));
        self::assertSame($before, [$parameters, $general, $setup]);
        self::assertStringStartsWith('<?xml version="1.0" encoding="utf-8"?>', $xml);
    }

    public function testValuesRemainLiteralUnicodeAndCarriageReturnText(): void
    {
        $value = "Zürich & XML <value> literal &amp;\r\nsecond line 😀";
        $xpath = self::xpath(JobCdlAssembler::build('synthetic-system', ['label' => $value]));
        self::assertSame($value, $xpath->evaluate('string(/chronos/evaluation/label)'));
        self::assertSame(0.0, $xpath->evaluate('count(/chronos/evaluation/label/value)'));
        self::assertSame(1.0, $xpath->evaluate('count(/chronos/setup)'));
        self::assertSame(0.0, $xpath->evaluate('count(/chronos/setup/* | /chronos/generator)'));
    }
}
