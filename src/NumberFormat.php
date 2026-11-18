<?php

declare(strict_types=1);

namespace Vensas\Hypaxel;

/**
 * Commonly used number format codes. Any Excel format code works wherever one of these does.
 */
final class NumberFormat
{
    public const GENERAL = 'General';
    public const INTEGER = '0';
    public const DECIMAL2 = '0.00';
    public const THOUSANDS = '#,##0';
    public const THOUSANDS2 = '#,##0.00';
    public const PERCENT = '0%';
    public const PERCENT2 = '0.00%';
    public const TEXT = '@';
    public const DATE = 'yyyy-mm-dd';
    public const DATETIME = 'yyyy-mm-dd hh:mm:ss';
    public const TIME = 'hh:mm:ss';
    public const DURATION = '[h]:mm:ss';

    private function __construct() {}
}
