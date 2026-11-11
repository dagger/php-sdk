<?php

declare(strict_types=1);

namespace Dagger\Client;

/**
 * The module a generated client calls: an address `serveModule` loads, and the
 * commit a Git address is pinned to.
 */
final readonly class ModuleTarget
{
    public function __construct(
        public string $address,
        public ?string $pin = null,
    ) {
    }
}
