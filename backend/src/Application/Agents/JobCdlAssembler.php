<?php
declare(strict_types=1);

namespace Chronos\Application\Agents;

use Chronos\Domain\Configuration\CdlDocument;
use Chronos\Http\HttpError;

/** Assemble agent XML from explicit settings and immutable parameter inputs. */
final class JobCdlAssembler
{
    public static function build(string|int $systemId, array $parameters, array $generalSettings = [], array $setupSettings = []): string
    {
        try {
            $document = new CdlDocument($systemId);
            foreach ($setupSettings as $parameter => $value) {
                $document->getSetup()->appendChild($document->createElement((string) $parameter, $value));
            }
            foreach (array_merge($generalSettings, $parameters) as $parameter => $value) {
                $document->getEvaluation()->appendChild($document->createElement((string) $parameter, $value));
            }
            return $document->toXML();
        } catch (\InvalidArgumentException) {
            throw new HttpError(409, 'cdl_invalid', 'The stored job parameters or system settings cannot form a valid agent XML configuration.');
        }
    }
}
