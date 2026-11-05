<?php

declare(strict_types=1);

namespace Ghispi\TreeTransform\Tests\Api;

/**
 * Renders the public API of src/ as stable text: every public type outside Internal\, with its public constants,
 * enum cases, properties and non-@internal methods, including parameter names and defaults (named arguments make
 * them part of the API).
 */
final class PublicApi
{
    private const string PREFIX = 'Ghispi\\TreeTransform\\';

    public static function render(string $srcDir): string
    {
        $lines = [];
        foreach (self::types($srcDir) as $type) {
            $lines = [...$lines, ...self::describe(new \ReflectionClass($type))];
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return list<class-string>
     */
    private static function types(string $srcDir): array
    {
        $types = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            $relative = substr($file->getPathname(), \strlen($srcDir) + 1);
            if ($file->getExtension() !== 'php' || str_starts_with($relative, 'Internal/')) {
                continue;
            }
            $name = self::PREFIX . str_replace(['/', '.php'], ['\\', ''], $relative);
            if (!class_exists($name) && !interface_exists($name) && !enum_exists($name)) {
                throw new \LogicException("{$file->getPathname()} does not declare {$name}.");
            }
            if (!self::isInternal(new \ReflectionClass($name))) {
                $types[] = $name;
            }
        }
        sort($types);

        return $types;
    }

    /**
     * @param \ReflectionClass<object> $class
     *
     * @return list<string>
     */
    private static function describe(\ReflectionClass $class): array
    {
        $kind = match (true) {
            $class->isInterface() => 'interface',
            $class->isEnum() => 'enum',
            default => implode(' ', array_filter([
                $class->isFinal() ? 'final' : '',
                $class->isReadOnly() ? 'readonly' : '',
                $class->isAbstract() ? 'abstract' : '',
                'class',
            ])),
        };
        $head = $kind . ' ' . self::short($class->getName());
        if ($class->isEnum()) {
            $backing = self::enum($class)->getBackingType();
            $head .= $backing !== null ? ": {$backing}" : '';
        }
        $parent = $class->getParentClass();
        if ($parent !== false) {
            $head .= ' extends ' . self::short($parent->getName());
        }
        $interfaces = array_filter(
            $class->getInterfaceNames(),
            static fn(string $i): bool => !str_starts_with($i, self::PREFIX . 'Internal\\')
                && !\in_array($i, ['UnitEnum', 'BackedEnum', 'Stringable'], true),
        );
        sort($interfaces);
        if ($interfaces !== []) {
            $head .= ($class->isInterface() ? ' extends ' : ' implements ') . implode(', ', array_map(self::short(...), $interfaces));
        }

        $lines = ['', $head];
        if ($class->isEnum()) {
            foreach (self::enum($class)->getCases() as $case) {
                $value = $case instanceof \ReflectionEnumBackedCase ? ' = ' . var_export($case->getBackingValue(), true) : '';
                $lines[] = "  case {$case->getName()}{$value}";
            }
        } else {
            foreach ($class->getReflectionConstants(\ReflectionClassConstant::IS_PUBLIC) as $constant) {
                if ($constant->getDeclaringClass()->getName() === $class->getName()) {
                    $lines[] = "  const {$constant->getName()} = " . var_export($constant->getValue(), true);
                }
            }
            foreach ($class->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                if ($property->getDeclaringClass()->getName() === $class->getName()) {
                    $lines[] = '  property ' . ($property->isReadOnly() ? 'readonly ' : '') . self::type($property->getType()) . " \${$property->getName()}";
                }
            }
        }
        $methods = $class->getMethods(\ReflectionMethod::IS_PUBLIC);
        usort($methods, static fn(\ReflectionMethod $a, \ReflectionMethod $b): int => strcmp($a->getName(), $b->getName()));
        foreach ($methods as $method) {
            if ($method->getDeclaringClass()->getName() !== $class->getName() || self::isInternal($method)
                || ($class->isEnum() && \in_array($method->getName(), ['cases', 'from', 'tryFrom'], true))) {
                continue;
            }
            $parameters = implode(', ', array_map(self::parameter(...), $method->getParameters()));
            // PHP 8.5 resolves `self` in reflected types, 8.4 does not: always write the class name.
            $return = $method->hasReturnType()
                ? ': ' . (preg_replace('/\bself\b/', self::short($class->getName()), self::type($method->getReturnType())) ?? '')
                : '';
            $lines[] = '  ' . ($method->isStatic() ? 'static ' : '') . "{$method->getName()}({$parameters}){$return}";
        }

        return $lines;
    }

    /**
     * @param \ReflectionClass<object> $class
     *
     * @return \ReflectionEnum<\UnitEnum>
     */
    private static function enum(\ReflectionClass $class): \ReflectionEnum
    {
        $name = $class->getName();
        if (!enum_exists($name)) {
            throw new \LogicException("{$name} is not an enum.");
        }

        return new \ReflectionEnum($name);
    }

    private static function parameter(\ReflectionParameter $parameter): string
    {
        $out = trim(self::type($parameter->getType()) . ' ' . ($parameter->isPassedByReference() ? '&' : '')
            . ($parameter->isVariadic() ? '...' : '') . '$' . $parameter->getName());
        if ($parameter->isDefaultValueAvailable()) {
            // Reflection's own rendering keeps initializers such as `new AutoAccessor()` readable.
            preg_match('/ = (.*) \]$/s', (string) $parameter, $match);
            $out .= ' = ' . self::shortenNames($match[1] ?? '?');
        }

        return $out;
    }

    private static function type(?\ReflectionType $type): string
    {
        return $type === null ? 'mixed' : self::shortenNames((string) $type);
    }

    private static function short(string $name): string
    {
        return str_starts_with($name, self::PREFIX) ? substr($name, \strlen(self::PREFIX)) : $name;
    }

    private static function shortenNames(string $code): string
    {
        return str_replace(['\\' . self::PREFIX, self::PREFIX], '', $code);
    }

    /**
     * @param \ReflectionClass<object>|\ReflectionMethod $reflection
     */
    private static function isInternal(\ReflectionClass|\ReflectionMethod $reflection): bool
    {
        $doc = $reflection->getDocComment();

        return $doc !== false && preg_match('/^\s*\*\s*@internal\b/m', $doc) === 1;
    }
}
