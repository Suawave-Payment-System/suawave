<?php

declare(strict_types=1);

namespace Suawave\Payment\Test\Unit;

use Suawave\Payment\Core\HttpResponse;
use Suawave\Payment\Core\HttpTransport;

final class ScriptedGateway implements HttpTransport
{
    public array $urls = [];

    public function __construct(private array $answers)
    {
    }

    public function get(string $url): HttpResponse
    {
        $this->urls[] = $url;
        return new HttpResponse(200, (string) json_encode(array_shift($this->answers)));
    }
}
