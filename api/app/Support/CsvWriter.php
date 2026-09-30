<?php

namespace App\Support;

/**
 * CSV para Excel em português: UTF-8 com BOM e separador `;` (o Excel pt-BR
 * abre `,` tudo numa coluna só). Campos de texto livre (comentário, títulos)
 * vêm de usuários, então células que o Excel trataria como fórmula são
 * neutralizadas (CSV injection).
 */
final class CsvWriter
{
    public const DELIMITER = ';';

    /** @param  resource  $handle */
    public static function writeBom($handle): void
    {
        fwrite($handle, "\xEF\xBB\xBF");
    }

    /**
     * @param  resource  $handle
     * @param  list<scalar|null>  $cells
     */
    public static function writeRow($handle, array $cells): void
    {
        // escape vazio: o padrão ("\\") está obsoleto no PHP 8.4 e corrompe aspas.
        fputcsv($handle, array_map(self::sanitize(...), $cells), self::DELIMITER, '"', '', "\r\n");
    }

    /** Prefixa com apóstrofo o que começa como fórmula (= + - @, tab ou CR). */
    public static function sanitize(mixed $value): string
    {
        $text = $value === null ? '' : (string) $value;

        if ($text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$text;
        }

        return $text;
    }
}
