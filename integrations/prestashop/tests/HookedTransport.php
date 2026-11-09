<?php

declare(strict_types=1);

namespace Suawave\Tests;

use Suawave\HttpResponse;
use Suawave\HttpTransport;

final class HookedTransport implements HttpTransport
{
    public array $requested = [];

    public function __construct(private array $responses, private $beforeCreate = null)
    {
    }

    public function get(string $url): HttpResponse
    {
        $this->requested[] = $url;
        if ($this->beforeCreate !== null && str_contains($url, '/create/')) {
            $hook = $this->beforeCreate;
            $this->beforeCreate = null;
            $hook();
        }
        return array_shift($this->responses);
    }
}
