<?php

declare(strict_types=1);

namespace Suawave;

interface PublicKeyProvider
{
    public function current(): string;

    public function refresh(): string;
}
