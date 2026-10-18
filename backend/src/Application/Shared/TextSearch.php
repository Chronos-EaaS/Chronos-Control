<?php
declare(strict_types=1);

namespace Chronos\Application\Shared;

/** SQL substring search across plain text and the existing HTML-encoded storage. */
final class TextSearch
{
    private static ?array $entities = null;
    private static ?string $entityPattern = null;

    /** Column expressions are application constants, never request input. */
    public static function condition(string $text, array $decodedColumns, array $rawColumns = []): array
    {
        $clauses = $arguments = [];
        foreach ($decodedColumns as $column) {
            [$expression, $values] = self::normalized($column, $text);
            $clauses[] = $expression . " LIKE ? ESCAPE '='";
            $arguments = [...$arguments, ...$values, Context::search($text)];
        }
        foreach ($rawColumns as $column) {
            $clauses[] = 'CONVERT(' . $column . " USING utf8mb4) LIKE ? ESCAPE '='";
            $arguments[] = Context::search($text);
        }
        return ['(' . implode(' OR ', $clauses) . ')', $arguments];
    }

    /** Decode complete tokens once; newly decoded text never becomes a token. */
    private static function normalized(string $column, string $text): array
    {
        $expression = 'REGEXP_REPLACE(CONVERT(' . $column . " USING utf8mb4), ?, CONCAT(CHAR(1 USING utf8mb4), '$0', CHAR(2 USING utf8mb4)), 1, 0, 'c')";
        $values = [self::entitiesPattern()];
        $characters = []; $operations = 0;
        foreach (self::entities() as $entity => $character) {
            $expression = 'REPLACE(' . $expression . ', ?, ?)';
            array_push($values, "\1" . $entity . "\2", $character);
            $characters[$character] = true;
            if (++$operations % 100 === 0) $expression = self::phase($expression);
        }
        // Named Latin/Greek entities also supply numeric equivalents, preserving
        // the database's accent/case collation. Supplementary values use the
        // current numeric writer and their Unicode case variants.
        foreach ([$text, mb_strtolower($text, 'UTF-8'), mb_strtoupper($text, 'UTF-8'), mb_convert_case($text, MB_CASE_FOLD, 'UTF-8')] as $variant) {
            foreach (mb_str_split($variant, 1, 'UTF-8') as $character) $characters[$character] = true;
        }
        foreach (array_keys($characters) as $character) {
            $character = (string) $character; // PHP converts numeric string keys.
            $codepoint = mb_ord($character, 'UTF-8');
            if (html_entity_decode('&#' . $codepoint . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8') !== $character) continue;
            $expression = 'REGEXP_REPLACE(' . $expression . ", ?, ?, 1, 0, 'i')";
            $pattern = "\1&#(?:0*" . $codepoint . '|[xX]0*' . dechex($codepoint) . ");\2";
            // ICU replacement strings interpret backslashes and dollar signs.
            array_push($values, $pattern, str_replace(['\\', '$'], ['\\\\', '\\$'], $character));
            if (++$operations % 100 === 0) $expression = self::phase($expression);
        }
        // Remaining protected numeric tokens cannot contain a requested value.
        // A forbidden input control keeps LIKE from joining adjacent fragments
        // or matching the spelling of an entity rather than its displayed text.
        $expression = 'REGEXP_REPLACE(' . $expression . ", ?, CHAR(3 USING utf8mb4), 1, 0, 'c')";
        $values[] = "\1[^\2]*\2";
        $plain = 'CONVERT(' . $column . ' USING utf8mb4)';
        $expression = "CASE WHEN LOCATE('&', " . $plain . ')=0 THEN ' . $plain . ' ELSE ' . $expression . ' END';
        return [$expression, $values];
    }

    private static function phase(string $expression): string
    {
        // LIMIT prevents derived-table merging and bounds expression depth on
        // the existing MySQL thread stack, including 250 distinct Unicode inputs.
        return '(SELECT chronos_text_stage.chronos_text_value FROM (SELECT ' . $expression . ' AS chronos_text_value LIMIT 1) AS chronos_text_stage)';
    }

    private static function entities(): array
    {
        if (self::$entities !== null) return self::$entities;
        // Actual writers use HTML401 htmlentities or htmlspecialchars, with
        // decimal supplementary entities. No browser/UI HTML is executed here.
        $entities = [];
        foreach (get_html_translation_table(HTML_ENTITIES, ENT_QUOTES | ENT_HTML401, 'UTF-8') as $character => $entity) {
            if ($entity[1] !== '#') $entities[$entity] = $character;
        }
        foreach (['&AMP;' => '&', '&LT;' => '<', '&GT;' => '>', '&QUOT;' => '"', '&apos;' => "'"] as $entity => $character) $entities[$entity] = $character;
        return self::$entities = $entities;
    }

    private static function numericPattern(int $base): string
    {
        $invalid = [...range(0, 8), ...range(11, 31), ...range(127, 159)];
        for ($plane = 0; $plane <= 16; $plane++) array_push($invalid, $plane * 65536 + 65534, $plane * 65536 + 65535);
        $excluded = implode('|', array_map(static fn(int $value): string => base_convert((string) $value, 10, $base), $invalid));
        if ($base === 16) {
            $excluded .= '|d[89abcdef][0-9a-f]{2}|fd[d-e][0-9a-f]';
            $allowed = '(?:[1-9a-f][0-9a-f]{0,4}|10[0-9a-f]{4})';
        } else {
            $excluded .= '|5529[6-9]|55[3-9][0-9]{2}|56[0-9]{3}|57[0-2][0-9]{2}|573[0-3][0-9]|5734[0-3]|6497[6-9]|649[89][0-9]|6500[0-7]';
            $allowed = '(?:[1-9][0-9]{0,5}|10[0-9]{5}|110[0-9]{4}|111[0-3][0-9]{3}|11140[0-9]{2}|111410[0-9]|111411[01])';
        }
        return '0*(?!(?:' . $excluded . ');)' . $allowed;
    }

    private static function entitiesPattern(): string
    {
        if (self::$entityPattern !== null) return self::$entityPattern;
        $names = implode('|', array_map(static fn(string $name): string => '\\Q' . $name . '\\E', array_keys(self::entities())));
        return self::$entityPattern = '(?:' . $names . '|&#' . self::numericPattern(10) . ';|&#[xX](?i:' . self::numericPattern(16) . ');)';
    }
}
