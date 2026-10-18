<?php
declare(strict_types=1);

/*
The MIT License (MIT)

Copyright (c) 2018 Databases and Information Systems Research Group,
University of Basel, Switzerland

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
 */

namespace Chronos\Domain\Configuration;

use DOMDocument;
use DOMElement;

final class CdlDocument
{

    private string|int $system;

    private DOMDocument $dom;
    private DOMElement $root;

    private ?DOMElement $setup = null;
    private ?DOMElement $evaluation = null;

    public function __construct(string|int $system)
    {
        if (!self::acceptsText((string) $system)) throw new \InvalidArgumentException('The system identifier contains XML-forbidden characters.');
        $this->system = $system;
        $this->dom = new DOMDocument('1.0', 'utf-8');
        $this->dom->preserveWhiteSpace = false;
        $this->dom->formatOutput = true;
        $this->root = $this->dom->createElement('chronos');
        $this->dom->appendChild($this->root);
        $this->getSetup();
        $this->getEvaluation();
    }

    public function getSetup(): DOMElement
    {
        if(empty($this->setup)) {
            $this->root->appendChild($this->setup = $this->dom->createElement('setup'));
        }
        return $this->setup;
    }

    public function getEvaluation(): DOMElement
    {
        if(empty($this->evaluation)) {
            $this->root->appendChild($this->evaluation = $this->dom->createElement('evaluation'));
            $this->evaluation->setAttribute('system', (string) $this->system);
        }
        return $this->evaluation;
    }

    public function createElement(string $name, mixed $value = null): DOMElement
    {
        if (!self::acceptsName($name)) throw new \InvalidArgumentException('The parameter name cannot form an XML element without a namespace.');
        if ($value !== null && (!is_scalar($value) || !self::acceptsText((string) $value))) {
            throw new \InvalidArgumentException('The parameter value cannot be represented as XML text.');
        }
        $element = $this->dom->createElement($name);
        if ($value !== null) {
            // Parameter values are text, including literal &, <, and entity-like
            // strings. DOM's createElement value argument interprets entities.
            $element->appendChild($this->dom->createTextNode((string) $value));
        }
        return $element;
    }

    /** XML 1.0 text retains supplementary Unicode, including U+1FFFE. */
    public static function acceptsText(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8')
            && preg_match('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', $value) === 0;
    }

    /** Match the actual DOM builder; agent documents define no namespace prefixes. */
    public static function acceptsName(string $name): bool
    {
        if ($name === '' || str_contains($name, ':') || !self::acceptsText($name)) return false;
        try { (new DOMDocument('1.0', 'utf-8'))->createElement($name); }
        catch (\DOMException) { return false; }
        return true;
    }

    public function toXML(): string
    {
        // DOM encodes CR as &#13; so parsing can preserve the original text.
        return $this->dom->saveXML();
    }

}
