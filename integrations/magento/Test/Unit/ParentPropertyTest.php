<?php

declare(strict_types=1);

namespace Suawave\Payment\Test\Unit;

use PHPUnit\Framework\TestCase;

final class ParentPropertyTest extends TestCase
{
    private const DIRECTORIES = ['Block', 'Controller', 'Core', 'Cron', 'Model', 'Observer'];
    private const MODIFIERS = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_READONLY, T_VAR, T_ABSTRACT, T_FINAL];
    private const IGNORED = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

    public function testNoModuleClassRedeclaresAParentPropertyIncompatibly(): void
    {
        $checked = [];
        $clashes = [];
        foreach (self::moduleFiles() as $file) {
            $class = self::parse((string) file_get_contents($file));
            if ($class === null || $class['parent'] === null) {
                continue;
            }
            self::assertTrue(class_exists($class['parent']), $class['name'] . ' extends ' . $class['parent'] . ', which has no stub');
            $checked[] = $class['name'];
            array_push($clashes, ...self::clashes($class));
        }

        self::assertContains('Suawave\\Payment\\Block\\Payment\\Instructions', $checked);
        self::assertContains('Suawave\\Payment\\Block\\Info\\Suawave', $checked);
        self::assertContains('Suawave\\Payment\\Model\\Payment\\Suawave', $checked);
        self::assertSame([], $clashes);
    }

    public function testTheTemplateStubDeclaresTheRealParentProperties(): void
    {
        $template = new \ReflectionClass(\Magento\Framework\View\Element\Template::class);
        foreach (['resolver', 'validator', '_template', 'pageConfig', 'templateContext', '_request', '_escaper', '_data'] as $name) {
            self::assertTrue($template->hasProperty($name), $name);
            self::assertTrue($template->getProperty($name)->isProtected(), $name);
            self::assertFalse($template->getProperty($name)->isReadOnly(), $name);
        }
    }

    public function testAReadonlyPromotedResolverOnTheInstructionsBlockIsReported(): void
    {
        $source = <<<'PHP'
<?php
namespace Suawave\Payment\Block\Payment;

use Suawave\Payment\Model\OrderResolver;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class Instructions extends Template
{
    public function __construct(
        Context $context,
        private readonly OrderResolver $resolver,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }
}
PHP;
        $class = self::parse($source);

        self::assertSame('Magento\\Framework\\View\\Element\\Template', $class['parent']);
        self::assertSame(
            ['Suawave\\Payment\\Block\\Payment\\Instructions::$resolver (private readonly OrderResolver) redeclares Magento\\Framework\\View\\Element\\Template::$resolver (protected)'],
            self::clashes($class)
        );
    }

    public function testNarrowerVisibilityAStaticMismatchOrAnAddedTypeIsReported(): void
    {
        $source = <<<'PHP'
<?php
namespace Suawave\Payment\Model\Payment;

use Magento\Payment\Model\Method\AbstractMethod;

class Broken extends AbstractMethod
{
    private $_code = 'x';
    protected static $_isOffline = true;
    protected bool $_canUseCheckout = true;
    protected $_canUseInternal = false;
    public $_infoBlockType = 'y';
    private $ownState;
}
PHP;
        $clashes = self::clashes(self::parse($source));

        self::assertCount(3, $clashes);
        self::assertStringContainsString('::$_code (private)', $clashes[0]);
        self::assertStringContainsString('::$_isOffline (protected static)', $clashes[1]);
        self::assertStringContainsString('::$_canUseCheckout (protected bool)', $clashes[2]);
    }

    private static function moduleFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];
        foreach (self::DIRECTORIES as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);
        return $files;
    }

    private static function clashes(array $class): array
    {
        $parent = new \ReflectionClass($class['parent']);
        $clashes = [];
        foreach ($class['properties'] as $name => $child) {
            if (!$parent->hasProperty($name)) {
                continue;
            }
            $inherited = $parent->getProperty($name);
            if ($inherited->isPrivate()) {
                continue;
            }
            $parentType = $inherited->hasType() ? (string) $inherited->getType() : '';
            $compatible = $child['static'] === $inherited->isStatic()
                && $child['readonly'] === $inherited->isReadOnly()
                && ($child['visibility'] === 'public' || ($child['visibility'] === 'protected' && $inherited->isProtected()))
                && self::sameType($child['type'], $parentType);
            if (!$compatible) {
                $clashes[] = sprintf(
                    '%s::$%s (%s) redeclares %s::$%s (%s)',
                    $class['name'],
                    $name,
                    self::describe($child['visibility'], $child['static'], $child['readonly'], $child['type']),
                    $inherited->getDeclaringClass()->getName(),
                    $name,
                    self::describe($inherited->isPublic() ? 'public' : 'protected', $inherited->isStatic(), $inherited->isReadOnly(), $parentType)
                );
            }
        }
        return $clashes;
    }

    private static function sameType(string $child, string $parent): bool
    {
        $normalise = static function (string $type): array {
            $parts = array_map(
                static fn (string $part): string => strtolower(substr(strrchr('\\' . ltrim($part, '?\\'), '\\'), 1)),
                preg_split('/[|&()]/', $type, -1, PREG_SPLIT_NO_EMPTY)
            );
            if (str_starts_with($type, '?')) {
                $parts[] = 'null';
            }
            sort($parts);
            return array_values(array_unique($parts));
        };
        return $normalise($child) === $normalise($parent);
    }

    private static function describe(string $visibility, bool $static, bool $readonly, string $type): string
    {
        return implode(' ', array_filter([$visibility, $static ? 'static' : '', $readonly ? 'readonly' : '', $type]));
    }

    private static function parse(string $source): ?array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($source),
            static fn (\PhpToken $token): bool => !$token->is(self::IGNORED)
        ));
        $namespace = '';
        $imports = [];
        $name = null;
        $parent = null;
        $properties = [];
        $depth = 0;
        $bodyDepth = null;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
                continue;
            }
            if ($token->text === '}') {
                $depth--;
                continue;
            }
            if ($name === null && $depth === 0 && $token->is(T_NAMESPACE)) {
                $namespace = $tokens[$i + 1]->text;
                continue;
            }
            if ($name === null && $depth === 0 && $token->is(T_USE)) {
                $target = ltrim($tokens[$i + 1]->text, '\\');
                $alias = $tokens[$i + 2]->is(T_AS) ? $tokens[$i + 3]->text : substr((string) strrchr('\\' . $target, '\\'), 1);
                $imports[strtolower($alias)] = $target;
                continue;
            }
            if ($name === null && $depth === 0 && $token->is(T_CLASS) && !($i > 0 && $tokens[$i - 1]->is([T_DOUBLE_COLON, T_NEW]))) {
                $name = ltrim($namespace . '\\' . $tokens[$i + 1]->text, '\\');
                if ($tokens[$i + 2]->is(T_EXTENDS)) {
                    $parent = self::resolve($tokens[$i + 3]->text, $namespace, $imports);
                }
                while ($tokens[$i]->text !== '{') {
                    $i++;
                }
                $depth++;
                $bodyDepth = $depth;
                continue;
            }
            if ($bodyDepth === null || $depth !== $bodyDepth || !$token->is(self::MODIFIERS)) {
                continue;
            }
            $modifiers = [];
            while ($tokens[$i]->is(self::MODIFIERS)) {
                $modifiers[] = $tokens[$i]->id;
                $i++;
            }
            if ($tokens[$i]->is([T_FUNCTION, T_CONST])) {
                $i--;
                continue;
            }
            $type = '';
            while (!$tokens[$i]->is(T_VARIABLE)) {
                $type .= $tokens[$i]->text;
                $i++;
            }
            $visibility = in_array(T_PRIVATE, $modifiers, true) ? 'private' : (in_array(T_PROTECTED, $modifiers, true) ? 'protected' : 'public');
            $properties[substr($tokens[$i]->text, 1)] = [
                'visibility' => $visibility,
                'static' => in_array(T_STATIC, $modifiers, true),
                'readonly' => in_array(T_READONLY, $modifiers, true),
                'type' => $type,
            ];
        }
        if ($name === null) {
            return null;
        }
        return ['name' => $name, 'parent' => $parent, 'properties' => $properties];
    }

    private static function resolve(string $name, string $namespace, array $imports): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }
        $segments = explode('\\', $name, 2);
        $first = strtolower($segments[0]);
        if (isset($imports[$first])) {
            return $imports[$first] . (isset($segments[1]) ? '\\' . $segments[1] : '');
        }
        return ltrim($namespace . '\\' . $name, '\\');
    }
}
