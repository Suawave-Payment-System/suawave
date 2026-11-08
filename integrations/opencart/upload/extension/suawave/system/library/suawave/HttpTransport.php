<?php

declare(strict_types=1);

namespace Suawave;

interface HttpTransport
{
    public function get(string $url): HttpResponse;
}
