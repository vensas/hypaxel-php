<?php

declare(strict_types=1);

/*
 * Streams a download without a framework. Serve it with PHP's built-in server:
 *
 *   php -S localhost:8000 examples/download.php
 *   curl -OJ http://localhost:8000/
 *
 * The generator stands in for a database cursor: rows are fetched while the response is on its way.
 */

use Vensas\Hypaxel\ColumnSpec;
use Vensas\Hypaxel\Xlsx;

require __DIR__ . '/../vendor/autoload.php';

/**
 * @return Generator<int, array{id: int, customer: string, amount: float, placed: DateTimeImmutable}>
 */
function orders(int $count): Generator
{
    $start = new DateTimeImmutable('2024-01-01 00:00:00');
    for ($i = 1; $i <= $count; ++$i) {
        yield [
            'id' => $i,
            'customer' => 'Customer ' . ($i % 5000),
            'amount' => $i * 1.25,
            'placed' => $start->modify("+{$i} minutes"),
        ];
    }
}

Xlsx::download('orders.xlsx', orders(200_000), sheetName: 'Orders', columns: [
    'id',
    new ColumnSpec('customer', header: 'Customer', width: 24.0),
    new ColumnSpec('amount', header: 'Amount', numberFormat: '#,##0.00'),
    new ColumnSpec('placed', header: 'Placed', width: 20.0, numberFormat: 'yyyy-mm-dd hh:mm'),
]);
