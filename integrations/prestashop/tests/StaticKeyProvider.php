<?php

declare(strict_types=1);

namespace Suawave\Tests;

use Suawave\PublicKeyProvider;

final class StaticKeyProvider implements PublicKeyProvider
{
    public int $refreshes = 0;

    public function __construct(private readonly string $cached, private readonly string $fresh)
    {
    }

    public function current(): string
    {
        return $this->cached;
    }

    public function refresh(): string
    {
        $this->refreshes++;
        return $this->fresh;
    }
}
