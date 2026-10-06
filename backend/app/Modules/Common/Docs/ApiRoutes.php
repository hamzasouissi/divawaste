<?php

namespace App\Modules\Common\Docs;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

/**
 * The two lists that must be identical: the API routes the application exposes, and the operations of the
 * generated OpenAPI file. Each entry reads "POST /api/v1/lots/{lot}/store".
 */
final class ApiRoutes
{
    /**
     * @return list<string>
     */
    public static function exposed(): array
    {
        $routes = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $routes[] = $method.' /'.str_replace('?}', '}', $route->uri());
            }
        }
        sort($routes);

        return $routes;
    }

    /**
     * @return list<string>
     */
    public static function documented(string $openApiFile): array
    {
        $operations = [];
        foreach (Yaml::parseFile($openApiFile)['paths'] ?? [] as $path => $methods) {
            foreach (array_intersect(array_keys($methods), ['get', 'post', 'put', 'patch', 'delete']) as $method) {
                $operations[] = strtoupper($method).' '.$path;
            }
        }
        sort($operations);

        return $operations;
    }
}
