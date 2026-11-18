<?php

declare(strict_types=1);

namespace Vensas\Hypaxel;

enum HorizontalAlignment: string
{
    case General = 'general';
    case Left = 'left';
    case Center = 'center';
    case Right = 'right';
    case Fill = 'fill';
    case Justify = 'justify';
    case CenterContinuous = 'centerContinuous';
    case Distributed = 'distributed';
}
