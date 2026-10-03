<?php

namespace App\Support;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Leitura mínima de .xlsx (primeira aba), sem dependências: devolve as linhas
 * como listas de valores brutos — texto, ou o número como string (datas do
 * Excel chegam como número serial; quem lê decide como interpretar).
 */
final class XlsxReader
{
    private const MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const RELS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const PACKAGE_RELS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /**
     * @return list<list<string|null>>
     */
    public static function rows(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('O arquivo não é uma planilha .xlsx válida.');
        }

        try {
            $shared = self::sharedStrings($zip);
            $sheet = $zip->getFromName(self::firstSheetPath($zip));
            if ($sheet === false) {
                throw new RuntimeException('A planilha não tem abas.');
            }

            $xml = self::load($sheet);
            $rows = [];

            foreach ($xml->children(self::MAIN)->sheetData->row as $row) {
                $values = [];
                foreach ($row->children(self::MAIN)->c as $cell) {
                    $attributes = $cell->attributes();
                    $index = self::columnIndex((string) $attributes['r']);
                    $type = (string) $attributes['t'];
                    $children = $cell->children(self::MAIN);

                    $values[$index] = match ($type) {
                        's' => $shared[(int) $children->v] ?? null,
                        'inlineStr' => self::text($children->is),
                        default => isset($children->v) ? (string) $children->v : null,
                    };
                }

                if ($values === []) {
                    $rows[] = [];

                    continue;
                }

                $line = array_fill(0, max(array_keys($values)) + 1, null);
                foreach ($values as $index => $value) {
                    $line[$index] = $value;
                }
                $rows[] = $line;
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    /** Número serial do Excel (sistema 1900) → "Y-m-d H:i:s". */
    public static function serialToDateTime(float $serial): string
    {
        $seconds = (int) round(($serial - 25569) * 86400);

        return gmdate('Y-m-d H:i:s', $seconds);
    }

    /**
     * @return list<string>
     */
    private static function sharedStrings(ZipArchive $zip): array
    {
        $content = $zip->getFromName('xl/sharedStrings.xml');
        if ($content === false) {
            return [];
        }

        $strings = [];
        foreach (self::load($content)->children(self::MAIN)->si as $item) {
            $strings[] = self::text($item);
        }

        return $strings;
    }

    private static function firstSheetPath(ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook === false || $rels === false) {
            return 'xl/worksheets/sheet1.xml';
        }

        $sheet = self::load($workbook)->children(self::MAIN)->sheets->sheet[0] ?? null;
        $id = $sheet !== null ? (string) $sheet->attributes(self::RELS)['id'] : '';

        foreach (self::load($rels)->children(self::PACKAGE_RELS) as $relationship) {
            if ((string) $relationship['Id'] === $id) {
                $target = ltrim((string) $relationship['Target'], '/');

                return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /** Texto de <si>/<is>: um <t> simples ou vários trechos formatados (<r><t>). */
    private static function text(?SimpleXMLElement $node): string
    {
        if ($node === null) {
            return '';
        }

        $children = $node->children(self::MAIN);
        if (isset($children->t)) {
            return (string) $children->t;
        }

        $text = '';
        foreach ($children->r as $run) {
            $text .= (string) $run->children(self::MAIN)->t;
        }

        return $text;
    }

    /** "C12" → 2 (A = 0). */
    private static function columnIndex(string $reference): int
    {
        preg_match('/^[A-Z]+/', $reference, $match);
        $index = 0;
        foreach (str_split($match[0] ?? 'A') as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }

    private static function load(string $content): SimpleXMLElement
    {
        $xml = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET);
        if ($xml === false) {
            throw new RuntimeException('Não foi possível ler a planilha.');
        }

        return $xml;
    }
}
