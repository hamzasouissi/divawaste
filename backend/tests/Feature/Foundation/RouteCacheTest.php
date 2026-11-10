<?php

namespace Tests\Feature\Foundation;

use Closure;
use Illuminate\Support\Facades\Route;
use Laravel\SerializableClosure\SerializableClosure;
use Laravel\SerializableClosure\Serializers\Native;
use Laravel\SerializableClosure\Serializers\Signed;
use Laravel\SerializableClosure\Support\SelfReference;
use Laravel\SerializableClosure\UnsignedSerializableClosure;
use ReflectionFunction;
use Tests\TestCase;

/**
 * The hosted servers cache their routes (`php artisan route:cache`). A cached route gets its closure back, but no other
 * object: a validation rule object (Rule::in, Rule::exists…) that a route takes from its file comes back broken and the
 * route answers 500 there, while it works where routes are not cached. Each route is put through the cache here, the
 * way Laravel writes and reads it, and what it holds must come back whole.
 */
class RouteCacheTest extends TestCase
{
    /** What Laravel lets back when it reads a cached closure (Illuminate\Routing\Route::runCallable). */
    private const RESTORED = [SerializableClosure::class, UnsignedSerializableClosure::class, Native::class, Signed::class, SelfReference::class];

    public function test_every_route_survives_the_route_cache(): void
    {
        $broken = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! $route->getAction('uses') instanceof Closure) {
                continue;
            }
            $cached = clone $route;
            $cached->prepareForSerialization();
            $closure = unserialize($cached->getAction('uses'), ['allowed_classes' => self::RESTORED])->getClosure();
            foreach ($this->brokenIn($closure, '') as $where) {
                $broken[] = $route->methods()[0].' '.$route->uri().'  '.$where;
            }
        }

        $this->assertSame([], $broken, 'These routes hold an object the route cache cannot restore: write the rule as text ("in:a,b", "exists:table,column") or build it inside a closure.');
    }

    /**
     * @return list<string>
     */
    private function brokenIn(mixed $value, string $path, int $depth = 0): array
    {
        if ($depth > 8) {
            return [];
        }
        if ($value instanceof \__PHP_Incomplete_Class) {
            return [$path];
        }
        $found = [];
        if ($value instanceof Closure) {
            foreach ((new ReflectionFunction($value))->getStaticVariables() as $name => $held) {
                array_push($found, ...$this->brokenIn($held, "{$path}\${$name}", $depth + 1));
            }
        } elseif (is_array($value)) {
            foreach ($value as $key => $held) {
                array_push($found, ...$this->brokenIn($held, "{$path}[{$key}]", $depth + 1));
            }
        }

        return $found;
    }
}
