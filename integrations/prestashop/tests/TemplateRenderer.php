<?php

declare(strict_types=1);

namespace Suawave\Tests;

use LogicException;

final class TemplateRenderer
{
    public static function render(string $path, array $vars): string
    {
        $tokens = preg_split('/(\{[^{}]+\})/', (string) file_get_contents($path), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $out = '';
        $stack = [];
        foreach ($tokens as $token) {
            $active = $stack === [] || end($stack)['active'];
            if (preg_match('/^\{if \$(\w+)\}$/D', $token, $m) === 1) {
                $truth = $active && (bool) self::value($vars, $m[1]);
                $stack[] = ['parent' => $active, 'active' => $truth, 'taken' => $truth];
                continue;
            }
            if (preg_match('/^\{elseif \$(\w+)\}$/D', $token, $m) === 1) {
                $frame = array_pop($stack) ?? throw new LogicException('elseif without if');
                $truth = $frame['parent'] && !$frame['taken'] && (bool) self::value($vars, $m[1]);
                $stack[] = ['parent' => $frame['parent'], 'active' => $truth, 'taken' => $frame['taken'] || $truth];
                continue;
            }
            if ($token === '{else}') {
                $frame = array_pop($stack) ?? throw new LogicException('else without if');
                $stack[] = ['parent' => $frame['parent'], 'active' => $frame['parent'] && !$frame['taken'], 'taken' => true];
                continue;
            }
            if ($token === '{/if}') {
                array_pop($stack) ?? throw new LogicException('unbalanced if');
                continue;
            }
            if (preg_match('/^\{\$(\w+)\|escape:\'htmlall\':\'UTF-8\'\}$/D', $token, $m) === 1) {
                if ($active) {
                    $out .= htmlspecialchars((string) self::value($vars, $m[1]), ENT_QUOTES, 'UTF-8');
                }
                continue;
            }
            if (preg_match('/^\{l s=\'([^\']*)\' d=\'Modules\.Suawave\.Shop\'\}$/D', $token, $m) === 1) {
                if ($active) {
                    $out .= $m[1];
                }
                continue;
            }
            if (str_starts_with($token, '{')) {
                throw new LogicException('unsupported template tag ' . $token);
            }
            if ($active) {
                $out .= $token;
            }
        }
        if ($stack !== []) {
            throw new LogicException('unclosed if');
        }
        return $out;
    }

    private static function value(array $vars, string $name): mixed
    {
        if (!array_key_exists($name, $vars)) {
            throw new LogicException('the template reads the unassigned variable ' . $name);
        }
        return $vars[$name];
    }
}
