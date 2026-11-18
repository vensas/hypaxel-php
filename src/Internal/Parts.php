<?php

declare(strict_types=1);

namespace Vensas\Hypaxel\Internal;

/**
 * Package-level parts written when the workbook completes.
 *
 * @internal
 */
final class Parts
{
    private const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const PACKAGE_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';
    private const CONTENT = 'application/vnd.openxmlformats-officedocument.spreadsheetml.';

    /**
     * @param list<SheetInfo> $sheets
     */
    public static function workbook(array $sheets, bool $usesFormulas, bool $date1904): string
    {
        $activeTab = 0;
        foreach ($sheets as $i => $sheet) {
            if (!$sheet->hidden) {
                $activeTab = $i;
                break;
            }
        }

        $x = Xml::DECLARATION . '<workbook xmlns="' . Xml::NS_MAIN . '" xmlns:r="' . Xml::NS_REL . '">'
            . ($date1904 ? '<workbookPr date1904="1"/>' : '')
            . '<bookViews><workbookView activeTab="' . $activeTab . '"/></bookViews><sheets>';
        foreach ($sheets as $i => $sheet) {
            $x .= '<sheet name="' . Xml::escape($sheet->name) . '" sheetId="' . ($i + 1) . '"'
                . ($sheet->hidden ? ' state="hidden"' : '') . ' r:id="rId' . ($i + 1) . '"/>';
        }
        $x .= '</sheets>';

        $names = '';
        foreach ($sheets as $i => $sheet) {
            if ($sheet->autoFilter === null) {
                continue;
            }
            [$firstRow, $firstColumn, $lastRow, $lastColumn] = $sheet->autoFilter;
            $names .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">\''
                . Xml::escape(\str_replace("'", "''", $sheet->name)) . '\'!$'
                . Xml::columnName($firstColumn) . '$' . $firstRow . ':$' . Xml::columnName($lastColumn) . '$' . $lastRow
                . '</definedName>';
        }
        if ($names !== '') {
            $x .= '<definedNames>' . $names . '</definedNames>';
        }

        return $x . ($usesFormulas ? '<calcPr calcId="191029" fullCalcOnLoad="1"/>' : '<calcPr calcId="191029"/>') . '</workbook>';
    }

    public static function workbookRels(int $sheetCount, bool $sharedStrings): string
    {
        $x = Xml::DECLARATION . '<Relationships xmlns="' . self::PACKAGE_REL . '">';
        for ($i = 1; $i <= $sheetCount; ++$i) {
            $x .= '<Relationship Id="rId' . $i . '" Type="' . self::REL . '/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        }
        $x .= '<Relationship Id="rId' . ($sheetCount + 1) . '" Type="' . self::REL . '/styles" Target="styles.xml"/>';
        if ($sharedStrings) {
            $x .= '<Relationship Id="rId' . ($sheetCount + 2) . '" Type="' . self::REL . '/sharedStrings" Target="sharedStrings.xml"/>';
        }

        return $x . '</Relationships>';
    }

    public static function contentTypes(int $sheetCount, int $tableCount, bool $sharedStrings): string
    {
        $x = Xml::DECLARATION . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="' . self::CONTENT . 'sheet.main+xml"/>';
        for ($i = 1; $i <= $sheetCount; ++$i) {
            $x .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="' . self::CONTENT . 'worksheet+xml"/>';
        }
        $x .= '<Override PartName="/xl/styles.xml" ContentType="' . self::CONTENT . 'styles+xml"/>';
        for ($i = 1; $i <= $tableCount; ++$i) {
            $x .= '<Override PartName="/xl/tables/table' . $i . '.xml" ContentType="' . self::CONTENT . 'table+xml"/>';
        }
        if ($sharedStrings) {
            $x .= '<Override PartName="/xl/sharedStrings.xml" ContentType="' . self::CONTENT . 'sharedStrings+xml"/>';
        }

        return $x . '</Types>';
    }

    /**
     * @param list<TableDefinition> $tables
     */
    public static function sheetRels(array $tables): string
    {
        $x = Xml::DECLARATION . '<Relationships xmlns="' . self::PACKAGE_REL . '">';
        foreach ($tables as $i => $table) {
            $x .= '<Relationship Id="rId' . ($i + 1) . '" Type="' . self::REL . '/table" Target="../tables/table' . $table->id . '.xml"/>';
        }

        return $x . '</Relationships>';
    }

    public static function rootRels(): string
    {
        return Xml::DECLARATION . '<Relationships xmlns="' . self::PACKAGE_REL . '"><Relationship Id="rId1" Type="'
            . self::REL . '/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    }
}
