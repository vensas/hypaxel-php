<?php

declare(strict_types=1);

namespace Vensas\Hypaxel\Tests\Support;

final class Order
{
    public function __construct(
        public int $id,
        public string $customer,
        public float $amount,
        public ?\DateTimeImmutable $placed = null,
        private readonly string $note = 'internal',
    ) {}

    public function note(): string
    {
        return $this->note;
    }
}
