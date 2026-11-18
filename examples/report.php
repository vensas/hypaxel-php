<?php

declare(strict_types=1);

/*
 * Full control with Writer: styles, several worksheets, formulas, merged cells.
 *
 *   php examples/report.php report.xlsx
 */

use Vensas\Hypaxel\BorderStyle;
use Vensas\Hypaxel\ColumnSpec;
use Vensas\Hypaxel\Formula;
use Vensas\Hypaxel\HorizontalAlignment;
use Vensas\Hypaxel\NumberFormat;
use Vensas\Hypaxel\SheetOptions;
use Vensas\Hypaxel\Style;
use Vensas\Hypaxel\Styled;
use Vensas\Hypaxel\Writer;

require __DIR__ . '/../vendor/autoload.php';

$path = $argv[1] ?? 'report.xlsx';

$writer = Writer::toFile($path);
$title = $writer->addStyle(Style::create()->bold()->fontSize(14)->align(HorizontalAlignment::Center));
$header = $writer->addStyle(Style::create()->bold()->fontColor('#FFFFFF')->fill('#305496')->align(HorizontalAlignment::Center));
$money = $writer->addStyle(Style::create()->numberFormat(NumberFormat::THOUSANDS2));
$total = $writer->addStyle(Style::create()->bold()->numberFormat(NumberFormat::THOUSANDS2)->border(BorderStyle::Thin));

$sheet = $writer->addWorksheet('Sales', new SheetOptions(freezeRows: 2, columnWidths: [20, 14, 14], tabColor: '#305496'));
$sheet->beginRow(height: 24.0)->write('Sales report', $title)->endRow();
$sheet->mergeCells(1, 1, 1, 3);
$sheet->writeRow(['Product', 'Date', 'Revenue'], $header);
$sheet->writeRows([
    ['Widget', new DateTimeImmutable('2024-05-01'), new Styled(1234.5, $money)],
    ['Gadget', new DateTimeImmutable('2024-05-02'), new Styled(99.99, $money)],
]);
$sheet->writeRow(['Total', null, new Styled(new Formula('SUM(C3:C4)'), $total)]);

$writer->writeWorksheet('Orders', [
    ['id' => 1, 'customer' => 'Ada', 'amount' => 1234.5, 'placed' => new DateTimeImmutable('2024-05-01 09:15:00')],
    ['id' => 2, 'customer' => 'Linus', 'amount' => 99.99, 'placed' => new DateTimeImmutable('2024-05-02 17:40:00')],
], columns: [
    new ColumnSpec('id', header: 'Order #', width: 10.0),
    new ColumnSpec('customer', header: 'Customer', width: 30.0),
    new ColumnSpec('amount', header: 'Amount', numberFormat: NumberFormat::THOUSANDS2),
    new ColumnSpec('placed', header: 'Placed', width: 20.0, numberFormat: NumberFormat::DATETIME),
]);

$writer->finish();
printf("Wrote %s (%d bytes)\n", $path, $writer->bytesWritten());
