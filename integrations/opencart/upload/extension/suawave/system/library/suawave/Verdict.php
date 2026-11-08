<?php

declare(strict_types=1);

namespace Suawave;

enum Verdict: string
{
    case Valid = 'valid';
    case MissingSignature = 'missing_signature';
    case MalformedSignature = 'malformed_signature';
    case InvalidSignature = 'invalid_signature';
}
