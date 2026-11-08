<?php

declare(strict_types=1);

namespace Suawave\Payment\Core;

interface HttpTransport
{
    public function get(string $url): HttpResponse;
}
