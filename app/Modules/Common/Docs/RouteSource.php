<?php

namespace App\Modules\Common\Docs;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Validation\Rule;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionFunction;
use ReflectionNamedType;
use UnitEnum;

/**
 * What the API documentation reads from the code of a route: its module, the comment written above it, the
 * permissions it checks, the rules it validates and the business errors it raises. The API is declared as closures
 * in app/Modules/<Module>/Routes/api.php, which the documentation generator cannot read by itself.
 *
 * Only used by `php artisan api:docs` (development dependency: knuckleswtf/scribe).
 */
final class RouteSource
{
    /** @var array<string, array{lines: list<string>, ast: array<Node>}> */
    private static array $files = [];

    private function __construct(
        private readonly ReflectionFunction $closure,
        private readonly string $file,
        private readonly int $start,
        private readonly int $end,
    ) {}

    public static function of(?Route $route): ?self
    {
        $uses = $route?->getAction('uses');
        if (! $uses instanceof Closure) {
            return null;
        }
        $closure = new ReflectionFunction($uses);
        $file = $closure->getFileName();

        return $file === false ? null : new self($closure, $file, (int) $closure->getStartLine(), (int) $closure->getEndLine());
    }

    public function module(): string
    {
        return preg_match('~Modules[\\\\/](\w+)[\\\\/]Routes~', $this->file, $match) === 1 ? $match[1] : 'Application';
    }

    /**
     * The comment written directly above the route, without its markers.
     */
    public function comment(): string
    {
        $lines = [];
        for ($index = $this->start - 2; $index >= 0; $index--) {
            $line = trim(self::file($this->file)['lines'][$index] ?? '');
            if ($line === '' || ! (str_starts_with($line, '//') || str_starts_with($line, '*') || str_starts_with($line, '/*'))) {
                break;
            }
            array_unshift($lines, trim((string) preg_replace('~^(//|/\*+|\*/|\*)\s?|\s*\*/$~', '', $line)));
        }

        return trim(implode(' ', array_filter($lines, fn (string $line) => $line !== '')));
    }

    /**
     * Permissions the route checks, in the route itself or in the authorize() of its form request.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        preg_match_all('~(?:\$allow\(\$request,\s*|(?<![\w>])can\(|->can\(|hasPermissionTo\(|canAnywhere\()\'([a-z_]+(?:\.[a-z_]+)+)\'~', $this->code()."\n".$this->formRequestCode(), $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Business rules the route can refuse with (HTTP 409): their stable codes.
     *
     * @return list<string>
     */
    public function businessErrors(): array
    {
        preg_match_all('~BusinessRuleViolation\(\'([A-Z0-9_]+)\'~', $this->code(), $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return class-string<FormRequest>|null
     */
    public function formRequest(): ?string
    {
        foreach ($this->closure->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), FormRequest::class)) {
                return $type->getName();
            }
        }

        return null;
    }

    /**
     * Rules of the first `->validate([...])` of the route, as far as they can be read without running it: plain
     * rules, Rule::in([...]) and Rule::enum(X::class). Database rules (exists, unique) and closures are left out.
     *
     * @return array<string, list<string>>
     */
    public function validationRules(): array
    {
        $finder = new NodeFinder;
        $closure = $finder->findFirst(self::file($this->file)['ast'], fn (Node $node) => ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction)
            && $node->getStartLine() === $this->start && $node->getEndLine() === $this->end);
        if ($closure === null) {
            return [];
        }

        // A route may delegate its validation to a helper closure of the same file (`use ($scope)`): read it too.
        $scopes = [$closure];
        foreach ($closure instanceof Node\Expr\Closure ? $closure->uses : [] as $use) {
            $helper = $finder->findFirst(self::file($this->file)['ast'], fn (Node $node) => $node instanceof Node\Expr\Assign && $node->var instanceof Node\Expr\Variable
                && $node->var->name === $use->var->name && ($node->expr instanceof Node\Expr\Closure || $node->expr instanceof Node\Expr\ArrowFunction));
            if ($helper instanceof Node\Expr\Assign) {
                $scopes[] = $helper->expr;
            }
        }

        $rules = [];
        $calls = $finder->find($scopes, fn (Node $node) => $node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier
            && $node->name->toString() === 'validate' && ($node->args[0] ?? null) instanceof Node\Arg && $node->args[0]->value instanceof Node\Expr\Array_);
        foreach ($calls as $call) {
            /** @var Node\Expr\MethodCall $call */
            $argument = $call->args[0];
            if (! $argument instanceof Node\Arg || ! $argument->value instanceof Node\Expr\Array_) {
                continue;
            }
            foreach ($argument->value->items as $item) {
                if ($item->key instanceof Node\Scalar\String_ && ! isset($rules[$item->key->value])) {
                    $rules[$item->key->value] = self::rulesOf($item->value);
                }
            }
        }

        return $rules;
    }

    private function code(): string
    {
        return implode("\n", array_slice(self::file($this->file)['lines'], $this->start - 1, $this->end - $this->start + 1));
    }

    private function formRequestCode(): string
    {
        $class = $this->formRequest();
        $file = $class === null ? false : (new \ReflectionClass($class))->getFileName();

        return $file === false ? '' : (string) file_get_contents($file);
    }

    /**
     * @return list<string>
     */
    private static function rulesOf(Node\Expr $value): array
    {
        if ($value instanceof Node\Scalar\String_) {
            return explode('|', $value->value);
        }
        if (! $value instanceof Node\Expr\Array_) {
            return [];
        }

        $rules = [];
        foreach ($value->items as $item) {
            $rule = $item->value;
            if ($rule instanceof Node\Scalar\String_) {
                $rules[] = $rule->value;
            } elseif ($rule instanceof Node\Expr\StaticCall && $rule->class instanceof Node\Name && ltrim($rule->class->toString(), '\\') === Rule::class
                && $rule->name instanceof Node\Identifier && ($rule->args[0] ?? null) instanceof Node\Arg) {
                $values = self::valuesOf($rule->name->toString(), $rule->args[0]->value);
                if ($values !== []) {
                    $rules[] = 'in:'.implode(',', $values);
                }
            }
        }

        return $rules;
    }

    /**
     * @return list<string>
     */
    private static function valuesOf(string $rule, Node\Expr $argument): array
    {
        if ($rule === 'in' && $argument instanceof Node\Expr\Array_) {
            $values = [];
            foreach ($argument->items as $item) {
                if ($item->value instanceof Node\Scalar\String_ || $item->value instanceof Node\Scalar\Int_) {
                    $values[] = (string) $item->value->value;
                }
            }

            return $values;
        }
        if ($rule === 'enum' && $argument instanceof Node\Expr\ClassConstFetch && $argument->class instanceof Node\Name) {
            $enum = ltrim($argument->class->toString(), '\\');

            return enum_exists($enum) ? array_map(fn (UnitEnum $case) => (string) ($case->value ?? $case->name), $enum::cases()) : [];
        }

        return [];
    }

    /**
     * @return array{lines: list<string>, ast: array<Node>}
     */
    private static function file(string $path): array
    {
        if (! isset(self::$files[$path])) {
            $code = (string) file_get_contents($path);
            $traverser = new NodeTraverser(new NameResolver);
            self::$files[$path] = [
                'lines' => preg_split('/\R/', $code) ?: [],
                'ast' => $traverser->traverse((new ParserFactory)->createForHostVersion()->parse($code) ?? []),
            ];
        }

        return self::$files[$path];
    }
}
