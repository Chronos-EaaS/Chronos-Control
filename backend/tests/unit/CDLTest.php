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

    public function testLazySectionsAreOnlyAppendedOnce(): void
    {
        $cdl = new CdlDocument('synthetic');
        self::assertSame($cdl->getSetup(), $cdl->getSetup());
        self::assertSame($cdl->getEvaluation(), $cdl->getEvaluation());
        self::assertSame($cdl->getData(), $cdl->getData());
        $xpath = new DOMXPath(self::document($cdl));
        self::assertSame(1.0, $xpath->evaluate('count(/chronos/setup)'));
        self::assertSame(1.0, $xpath->evaluate('count(/chronos/evaluation)'));
        self::assertSame(1.0, $xpath->evaluate('count(/chronos/generator)'));
        self::assertSame('synthetic', $xpath->evaluate('string(/chronos/generator/@system)'));
    }

    public function testClonedEvaluationAndSetupCanBeModifiedWithoutChangingTheOriginal(): void
    {
        $original = new CdlDocument('synthetic');
        $original->getEvaluation()->appendChild($original->createElement('workers', '2'));
        $copy = clone $original;
        $copy->getEvaluation()->appendChild($copy->createElement('batch', 'large'));
        $copy->getSetup()->appendChild($copy->createElement('enabled', 'true'));
        $originalXPath = new DOMXPath(self::document($original));
        $copyXPath = new DOMXPath(self::document($copy));
        self::assertSame(0.0, $originalXPath->evaluate('count(/chronos/evaluation/batch | /chronos/setup/enabled)'));
        self::assertSame('2', $copyXPath->evaluate('string(/chronos/evaluation/workers)'));
        self::assertSame('large', $copyXPath->evaluate('string(/chronos/evaluation/batch)'));
        self::assertSame('true', $copyXPath->evaluate('string(/chronos/setup/enabled)'));
    }

    public function testClonedGeneratorMustBelongToTheClonedDocument(): void
    {
        $original = new CdlDocument('synthetic');
        $original->getData()->appendChild($original->createElement('seed', '42'));
        $copy = clone $original;
        self::assertNotSame($original->getData(), $copy->getData());
        $copy->getData()->appendChild($copy->createElement('copyOnly', 'yes'));
        self::assertSame('42', (new DOMXPath(self::document($copy)))->evaluate('string(/chronos/generator/seed)'));
        self::assertSame('yes', (new DOMXPath(self::document($copy)))->evaluate('string(/chronos/generator/copyOnly)'));
        self::assertSame(0.0, (new DOMXPath(self::document($original)))->evaluate('count(/chronos/generator/copyOnly)'));
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

    public function testCloningBeforeGeneratorCreationKeepsSectionsIndependent(): void
    {
        $original = new CdlDocument('synthetic');
        $copy = clone $original;
        $copy->getData()->appendChild($copy->createElement('seed', '7'));
        self::assertSame(0.0, (new DOMXPath(self::document($original)))->evaluate('count(/chronos/generator)'));
        self::assertSame('7', (new DOMXPath(self::document($copy)))->evaluate('string(/chronos/generator/seed)'));
    }
}
