<?php

declare(strict_types=1);

namespace Chronos\Tests;

use CDL_Library;
use Chronos\Tests\Support\KnownLegacyDefect;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class CDLTest extends TestCase
{
    use KnownLegacyDefect;

    private static function document(CDL_Library $cdl): DOMDocument
    {
        $document = new DOMDocument();
        self::assertTrue($document->loadXML($cdl->toXML()));

        return $document;
    }

    public function testDocumentContainsSetupAndSystemEvaluationWithUnicodeValues(): void
    {
        $cdl = new CDL_Library('synthetic-system');
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
        $cdl = new CDL_Library('synthetic');
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
        $original = new CDL_Library('synthetic');
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

    #[Group('known-defect')]
    public function testClonedGeneratorMustBelongToTheClonedDocument(): void
    {
        $this->assertCorrectBehaviorOrRecordDefect('CDL-CLONE', 'Legacy retains the original generator DOM reference after cloning.', function (): void {
            $original = new CDL_Library('synthetic');
            $original->getData()->appendChild($original->createElement('seed', '42'));
            $copy = clone $original;
            self::assertNotSame($original->getData(), $copy->getData());
            self::assertSame('42', (new DOMXPath(self::document($copy)))->evaluate('string(/chronos/generator/seed)'));
        });
    }

    #[Group('known-defect')]
    public function testParameterTextContainingAmpersandsMustRoundTripAsText(): void
    {
        $this->assertCorrectBehaviorOrRecordDefect('CDL-ESCAPING', 'Legacy createElement interprets ampersands as entity references.', function (): void {
            $prior = libxml_use_internal_errors(true);
            try {
                $cdl = new CDL_Library('synthetic');
                $cdl->getEvaluation()->appendChild($cdl->createElement('label', 'A & B'));
                self::assertSame('A & B', (new DOMXPath(self::document($cdl)))->evaluate('string(/chronos/evaluation/label)'));
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($prior);
            }
        });
    }
}
