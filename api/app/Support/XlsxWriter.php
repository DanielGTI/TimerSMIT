<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * Planilha .xlsx mínima (uma aba), escrita direto em XML com ZipArchive, sem
 * dependências. Só o que o relatório mensal precisa: texto, duração (número
 * em dias com formato [h]:mm:ss, que o Excel soma), estilos fixos, largura de
 * coluna e altura de linha.
 */
final class XlsxWriter
{
    public const PLAIN = 0;

    public const TITLE = 1;

    public const NOTE = 2;

    public const HEADER = 3;

    public const ROW_LABEL = 4;

    public const DURATION = 5;

    public const HIGHLIGHT_LABEL = 6;

    public const HIGHLIGHT_DURATION = 7;

    public const TOTAL_LABEL = 8;

    public const TOTAL_DURATION = 9;

    /** @var array<int, array<int, array{type: string, value: string|float|null, style: int}>> */
    private array $cells = [];

    /** @var array<int, float> */
    private array $widths = [];

    /** @var array<int, float> */
    private array $heights = [];

    public function text(int $row, int $col, string $value, int $style = self::PLAIN): self
    {
        $this->cells[$row][$col] = ['type' => 'text', 'value' => $value, 'style' => $style];

        return $this;
    }

    /** Segundos como duração do Excel (fração de dia). */
    public function duration(int $row, int $col, int $seconds, int $style = self::DURATION): self
    {
        $this->cells[$row][$col] = ['type' => 'number', 'value' => $seconds / 86400, 'style' => $style];

        return $this;
    }

    /** Número puro (ex.: data serial do Excel). */
    public function number(int $row, int $col, float $value, int $style = self::PLAIN): self
    {
        $this->cells[$row][$col] = ['type' => 'number', 'value' => $value, 'style' => $style];

        return $this;
    }

    /** Célula vazia, só com o estilo (borda). */
    public function blank(int $row, int $col, int $style = self::DURATION): self
    {
        $this->cells[$row][$col] = ['type' => 'blank', 'value' => null, 'style' => $style];

        return $this;
    }

    public function width(int $col, float $width): self
    {
        $this->widths[$col] = $width;

        return $this;
    }

    public function height(int $row, float $height): self
    {
        $this->heights[$row] = $height;

        return $this;
    }

    public function save(string $path, string $sheetName): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível criar a planilha.');
        }

        $zip->addFromString('[Content_Types].xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>
XML);
        $zip->addFromString('_rels/.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML);
        $zip->addFromString('xl/_rels/workbook.xml.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML);
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.self::escape(self::sheetName($sheetName)).'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/styles.xml', self::styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet());

        $zip->close();
    }

    private function sheet(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        if ($this->widths !== []) {
            ksort($this->widths);
            $xml .= '<cols>';
            foreach ($this->widths as $col => $width) {
                $xml .= sprintf('<col min="%d" max="%d" width="%s" customWidth="1"/>', $col, $col, $width);
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';
        $rows = array_unique([...array_keys($this->cells), ...array_keys($this->heights)]);
        sort($rows);

        foreach ($rows as $row) {
            $xml .= '<row r="'.$row.'"'.(isset($this->heights[$row]) ? ' ht="'.$this->heights[$row].'" customHeight="1"' : '').'>';
            $cells = $this->cells[$row] ?? [];
            ksort($cells);

            foreach ($cells as $col => $cell) {
                $ref = self::column($col).$row;
                $xml .= match ($cell['type']) {
                    'text' => '<c r="'.$ref.'" s="'.$cell['style'].'" t="inlineStr"><is><t xml:space="preserve">'.self::escape((string) $cell['value']).'</t></is></c>',
                    'number' => '<c r="'.$ref.'" s="'.$cell['style'].'"><v>'.self::formatNumber((float) $cell['value']).'</v></c>',
                    default => '<c r="'.$ref.'" s="'.$cell['style'].'"/>',
                };
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData><pageSetup orientation="landscape"/></worksheet>';
    }

    private static function styles(): string
    {
        $border = '<border><left style="thin"><color rgb="FFBFBFBF"/></left><right style="thin"><color rgb="FFBFBFBF"/></right>'
            .'<top style="thin"><color rgb="FFBFBFBF"/></top><bottom style="thin"><color rgb="FFBFBFBF"/></bottom><diagonal/></border>';
        $center = '<alignment horizontal="center" vertical="center"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="[h]:mm:ss"/></numFmts>'
            .'<fonts count="4">'
            .'<font><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="16"/><name val="Calibri"/></font>'
            .'<font><i/><sz val="10"/><color rgb="FF595959"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="4">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFD9D9D9"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFFFF2CC"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'.$border.'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="10">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1">'.$center.'</xf>'
            .'<xf numFmtId="0" fontId="1" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            .'<xf numFmtId="164" fontId="1" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'.$center.'</xf>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            .'<xf numFmtId="164" fontId="1" fillId="2" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'.$center.'</xf>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    /** 1 → A, 27 → AA. */
    public static function column(int $col): string
    {
        $name = '';
        for (; $col > 0; $col = intdiv($col - 1, 26)) {
            $name = chr(65 + ($col - 1) % 26).$name;
        }

        return $name;
    }

    private static function formatNumber(float $value): string
    {
        return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.') ?: '0';
    }

    private static function escape(string $text): string
    {
        // Remove caracteres de controle que o XML não aceita.
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text);

        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Nome de aba: até 31 caracteres, sem : \ / ? * [ ]. */
    private static function sheetName(string $name): string
    {
        return mb_substr(str_replace([':', '\\', '/', '?', '*', '[', ']'], ' ', $name), 0, 31) ?: 'Planilha';
    }
}
