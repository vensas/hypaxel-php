<?php

declare(strict_types=1);

namespace Vensas\Hypaxel;

enum BorderStyle: string
{
    case None = 'none';
    case Thin = 'thin';
    case Medium = 'medium';
    case Dashed = 'dashed';
    case Dotted = 'dotted';
    case Thick = 'thick';
    case Double = 'double';
    case Hair = 'hair';
    case MediumDashed = 'mediumDashed';
    case DashDot = 'dashDot';
    case MediumDashDot = 'mediumDashDot';
    case DashDotDot = 'dashDotDot';
    case MediumDashDotDot = 'mediumDashDotDot';
    case SlantDashDot = 'slantDashDot';
}
