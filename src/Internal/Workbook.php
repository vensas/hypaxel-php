<?php

declare(strict_types=1);

namespace Vensas\Hypaxel\Internal;

use Vensas\Hypaxel\Options;

/**
 * State shared by a writer and its worksheets.
 *
 * @internal
 */
final class Workbook
{
    public readonly StyleRegistry $styles;
    public readonly ?SharedStrings $sharedStrings;
    public bool $usesFormulas = false;

    /** @var list<SheetInfo> */
    public array $sheets = [];

    public function __construct(
        public readonly ZipStream $zip,
        public readonly Options $options,
    ) {
        $this->styles = new StyleRegistry();
        $this->sharedStrings = $options->sharedStrings ? new SharedStrings() : null;
    }
}
