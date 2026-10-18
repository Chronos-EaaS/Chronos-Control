<?php

declare(strict_types=1);

namespace Chronos\Tests;

use Chronos\Domain\Configuration\CdlDocument;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CDLTest extends TestCase
{
    private static function document(CdlDocument $cdl): DOMDocument
    {
        $document = new DOMDocument();
        self::assertTrue($document->loadXML($cdl->toXML()));

        return $document;
    }

    public function testDocumentContainsSetupAndSystemEvaluationWithUnicodeValues(): void
    {
        $cdl = new CdlDocument('synthetic-system');
        $cdl->getSetup()->appendChild($cdl->createElement('threads', '4'));
        $cdl->getEvaluation()->appendChild($cdl->createElement('query.mode', 'café'));
        $xpath = new DOMXPath(self::document($cdl));
        self::assertSame('1.0', self::document($cdl)->xmlVersion);
        self::assertSame('synthetic-system', $xpath->evaluate('string(/chronos/evaluation/@system)'));
        self::assertSame('4', $xpath->evaluate('string(/chronos/setup/threads)'));
        self::assertSame('café', $xpath->evaluate('string(/chronos/evaluation/query.mode)'));
        self::assertSame(0.0, $xpath->evaluate('count(/chronos/generator)'));
    }

    public function testRepeatedSectionAccessDoesNotDuplicateSetupOrEvaluation(): void
    {
        $cdl = new CdlDocument('synthetic');
        self::assertSame($cdl->getSetup(), $cdl->getSetup());
        self::assertSame($cdl->getEvaluation(), $cdl->getEvaluation());
        $xpath = new DOMXPath(self::document($cdl));
        self::assertSame(1.0, $xpath->evaluate('count(/chronos/setup)'));
        self::assertSame(1.0, $xpath->evaluate('count(/chronos/evaluation)'));
        self::assertSame(0.0, $xpath->evaluate('count(/chronos/generator)'));
    }

    public function testSeparateJobDocumentsKeepTheirOwnSettingsParametersAndSystem(): void
    {
        $original = new CdlDocument('synthetic');
        $original->getEvaluation()->appendChild($original->createElement('workers', '2'));
        $other = new CdlDocument(7);
        $other->getEvaluation()->appendChild($other->createElement('batch', 'large'));
        $other->getSetup()->appendChild($other->createElement('enabled', 'true'));
        $originalXPath = new DOMXPath(self::document($original));
        $otherXPath = new DOMXPath(self::document($other));
        self::assertSame(0.0, $originalXPath->evaluate('count(/chronos/evaluation/batch | /chronos/setup/enabled)'));
        self::assertSame('2', $originalXPath->evaluate('string(/chronos/evaluation/workers)'));
        self::assertSame('synthetic', $originalXPath->evaluate('string(/chronos/evaluation/@system)'));
        self::assertSame(0.0, $otherXPath->evaluate('count(/chronos/evaluation/workers)'));
        self::assertSame('7', $otherXPath->evaluate('string(/chronos/evaluation/@system)'));
        self::assertSame('large', $otherXPath->evaluate('string(/chronos/evaluation/batch)'));
        self::assertSame('true', $otherXPath->evaluate('string(/chronos/setup/enabled)'));
    }

    public static function parameterText(): array
    {
        return [
            ['A & B'], ['Zürich & XML <value>'], ['literal &amp; &lt; &quot;'],
            ['quotes " and \''], ["line one\nline two\r\nline three"], ['0'], [''],
        ];
    }

    #[DataProvider('parameterText')]
    public function testParameterTextMustRoundTripWithoutLossOrMarkupInterpretation(string $value): void
    {
        $cdl = new CdlDocument('synthetic');
        $cdl->getEvaluation()->appendChild($cdl->createElement('label', $value));
        self::assertSame($value, (new DOMXPath(self::document($cdl)))->evaluate('string(/chronos/evaluation/label)'));
    }

}
