<?php

declare(strict_types=1);

class Cart
{
    public function __construct(private readonly bool $virtual) {}

    public function isVirtualCart(): bool
    {
        return $this->virtual;
    }
}

class Context
{
    public function __construct(public Cart $cart) {}
}

require dirname(__DIR__) . '/bootstrap.php';

use Jzvikas\OnePageCheckout\Checkout\CheckoutMutation;
use Jzvikas\OnePageCheckout\Checkout\CheckoutSection;
use Jzvikas\OnePageCheckout\Checkout\CheckoutSectionDependencyResolver;

function assertSectionDependency(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$values = static fn (array $sections): array => array_map(
    static fn (CheckoutSection $section): string => $section->value,
    $sections,
);

$resolver = new CheckoutSectionDependencyResolver();

assertSectionDependency(
    $values($resolver->affectedBy(
        CheckoutMutation::AddressSelectionUpdated,
        new Context(new Cart(false)),
    )) === ['addresses', 'delivery', 'payment', 'agreements', 'summary'],
    'physical address mutation must refresh delivery and every downstream section',
);

assertSectionDependency(
    $values($resolver->affectedBy(
        CheckoutMutation::AddressSelectionUpdated,
        new Context(new Cart(true)),
    )) === ['addresses', 'payment', 'agreements', 'summary'],
    'virtual address mutation must omit the intentionally absent delivery DOM section',
);

assertSectionDependency(
    $values($resolver->affectedBy(CheckoutMutation::AddressSelectionUpdated))
        === ['addresses', 'delivery', 'payment', 'agreements', 'summary'],
    'context-free dependency inspection must stay conservative',
);

fwrite(STDOUT, "Checkout section dependency context smoke tests passed.\n");
