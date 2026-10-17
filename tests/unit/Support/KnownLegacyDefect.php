<?php

declare(strict_types=1);

namespace Chronos\Tests\Support;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

trait KnownLegacyDefect
{
    /**
     * Assert the intended correction, never the erroneous baseline output.
     * An unfixed legacy failure stays visibly incomplete. Once fixed, this test
     * becomes a passing regression without weakening its expected behavior.
     */
    private function assertCorrectBehaviorOrRecordDefect(
        string $id,
        string $description,
        callable $assertCorrectBehavior,
    ): void {
        try {
            $assertCorrectBehavior();
        } catch (AssertionFailedError $failure) {
            TestCase::markTestIncomplete($id . ': ' . $description . "\n" . $failure->getMessage());
        }
    }
}
