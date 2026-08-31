<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Cache;

use ErrorException;
use Laravel\SerializableClosure\SerializableClosure;
use Lsr\Core\Routing\AliasRoute;
use Lsr\Core\Routing\Exceptions\RouteCacheCompilationException;
use Lsr\Core\Routing\Interfaces\RouteParamValidatorInterface;
use Lsr\Core\Routing\LocalizedRoute;
use Lsr\Core\Routing\Route;
use Lsr\Core\Routing\RouteParameter;
use Lsr\Core\Routing\Router;
use Lsr\Core\Routing\ServiceReference;
use Lsr\Enums\RequestMethod;
use Lsr\Interfaces\RouteInterface;
use Psr\Http\Server\MiddlewareInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use SplObjectStorage;
use Throwable;
use UnexpectedValueException;

final class CompiledRouteCache
{
    private const int FORMAT_VERSION = 1;

    /**
     * @param string[] $routeSources
     * @param string[] $controllerSources
     */
    public function __construct(
        public readonly string $file,
        public readonly bool $autoCompile = true,
        public readonly bool $checkTimestamps = false,
        private readonly array $routeSources = [],
        private readonly array $controllerSources = [],
    ) {
    }

    public function load(Router $router): bool
    {
        if (!is_file($this->file)) {
            return false;
        }

        try {
            $data = require $this->file;
            if (!is_array($data) || ($data['version'] ?? null) !== self::FORMAT_VERSION) {
                return false;
            }
            if ($this->checkTimestamps && ($data['manifest'] ?? null) !== $this->createSourceManifest()) {
                return false;
            }
            $this->hydrate($router, $data);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function compile(Router $router): void
    {
        $data = $this->encode($router);
        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($data, true) . ";\n";
        $this->writeAtomically($contents);
    }

    public function clear(): void
    {
        if (!is_dir(dirname($this->file))) {
            return;
        }

        $lock = fopen($this->file . '.lock', 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RouteCacheCompilationException(sprintf('Unable to lock route cache "%s".', $this->file));
        }

        try {
            foreach ([$this->file, $this->file . '.tmp'] as $file) {
                if (is_file($file) && !unlink($file)) {
                    throw new RouteCacheCompilationException(sprintf('Unable to remove route cache file "%s".', $file));
                }
            }
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($this->file, true);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function encode(Router $router): array
    {
        /** @var SplObjectStorage<Route,int> $routeIds */
        $routeIds = new SplObjectStorage();
        /** @var list<Route> $routes */
        $routes = [];

        $registerRoute = null;
        $registerRoute = function (RouteInterface $route) use (&$registerRoute, $routeIds, &$routes): int {
            if (!$route instanceof Route) {
                throw new RouteCacheCompilationException(
                    sprintf('Route cache cannot compile custom route type %s.', $route::class),
                );
            }
            if ($routeIds->contains($route)) {
                return $routeIds[$route];
            }

            $id = count($routes);
            $routeIds[$route] = $id;
            $routes[] = $route;

            if ($route instanceof LocalizedRoute) {
                $registerRoute($route->parent);
            } elseif ($route instanceof AliasRoute) {
                $registerRoute($route->redirectTo);
            }
            foreach ($route->localizedRoutes as $localizedRoute) {
                $registerRoute($localizedRoute);
            }

            return $id;
        };

        $tree = $this->encodeNode($router->getAvailableRoutes(), $registerRoute);
        $named = [];
        foreach ($router->getNamedRoutes() as $name => $route) {
            $named[$name] = $registerRoute($route);
        }

        /** @var SplObjectStorage<object,int> $objectIds */
        $objectIds = new SplObjectStorage();
        /** @var list<object> $objects */
        $objects = [];
        /** @var array<int,list<string>> $objectContexts */
        $objectContexts = [];
        $routeData = [];
        foreach ($routes as $id => $route) {
            $context = $route->getMethod()->value . ' /' . implode('/', $route->getPath());
            $definition = [
                'type' => match (true) {
                    $route instanceof LocalizedRoute => 'localized',
                    $route instanceof AliasRoute => 'alias',
                    $route::class === Route::class => 'route',
                    default => throw new RouteCacheCompilationException(
                        sprintf('Route cache cannot compile route type %s at %s.', $route::class, $context),
                    ),
                },
                'method' => $route->getMethod()->value,
                'path' => $route->getReadable(),
                'name' => $route->getName(),
                'locale' => $route->getLocale(),
                'middleware' => [],
                'validators' => [],
                'localized' => [],
            ];

            if ($route instanceof LocalizedRoute) {
                $definition['parent'] = $registerRoute($route->parent);
            } elseif ($route instanceof AliasRoute) {
                $definition['redirectTo'] = $registerRoute($route->redirectTo);
            } else {
                $definition['handler'] = $this->encodeHandler(
                    $route->getCacheHandler(),
                    $router,
                    $objectIds,
                    $objects,
                    $objectContexts,
                    $context,
                );
            }

            foreach ($route->getResolvedMiddlewareDefinitions() as $middleware) {
                $definition['middleware'][] = $this->encodeDependency(
                    $middleware,
                    $router,
                    $objectIds,
                    $objects,
                    $objectContexts,
                    $context . ' middleware',
                );
            }
            foreach ($route->getParamValidatorDefinitions() as $name => $validators) {
                foreach ($validators as $validator) {
                    $definition['validators'][$name][] = $this->encodeDependency(
                        $validator,
                        $router,
                        $objectIds,
                        $objects,
                        $objectContexts,
                        $context . ' parameter ' . $name,
                    );
                }
            }
            foreach ($route->localizedRoutes as $locale => $localizedRoute) {
                $definition['localized'][$locale] = $registerRoute($localizedRoute);
            }
            $routeData[$id] = $definition;
        }

        $serializedObjects = $this->serializeObjectPool($objects, $objectContexts);

        return [
            'version' => self::FORMAT_VERSION,
            'manifest' => $this->createSourceManifest(),
            'tree' => $tree,
            'routes' => $routeData,
            'named' => $named,
            'objects' => $serializedObjects,
        ];
    }

    /**
     * @param callable(RouteInterface):int $routeId
     * @return array<string,mixed>
     */
    private function encodeNode(mixed $node, callable $routeId): array
    {
        if ($node instanceof RouteInterface) {
            return ['type' => 'route', 'id' => $routeId($node)];
        }
        if ($node instanceof RouteParameter) {
            return [
                'type' => 'parameter',
                'name' => $node->name,
                'optional' => $node->optional,
                'default' => $node->default,
                'routes' => $this->encodeNode($node->routes, $routeId),
            ];
        }
        if (!is_array($node)) {
            throw new RouteCacheCompilationException(
                sprintf('Route cache encountered unsupported matcher node %s.', get_debug_type($node)),
            );
        }

        $children = [];
        foreach ($node as $key => $child) {
            $children[(string) $key] = $this->encodeNode($child, $routeId);
        }
        return ['type' => 'array', 'children' => $children];
    }

    /**
     * @param string|array{0:class-string|object,1:string}|SerializableClosure $handler
     * @param SplObjectStorage<object,int>                                    $objectIds
     * @param list<object>                                                     $objects
     * @param array<int,list<string>>                                          $objectContexts
     * @return array<string,mixed>
     */
    private function encodeHandler(
        string|array|SerializableClosure $handler,
        Router $router,
        SplObjectStorage $objectIds,
        array &$objects,
        array &$objectContexts,
        string $context,
    ): array {
        if (is_string($handler)) {
            return ['type' => 'callable', 'value' => $handler];
        }
        if ($handler instanceof SerializableClosure) {
            return [
                'type' => 'closure',
                'dependency' => $this->encodeDependency(
                    $handler,
                    $router,
                    $objectIds,
                    $objects,
                    $objectContexts,
                    $context . ' handler',
                ),
            ];
        }
        if (is_string($handler[0])) {
            return ['type' => 'class_method', 'class' => $handler[0], 'method' => $handler[1]];
        }
        return [
            'type' => 'object_method',
            'object' => $this->encodeDependency(
                $handler[0],
                $router,
                $objectIds,
                $objects,
                $objectContexts,
                $context . ' handler',
            ),
            'method' => $handler[1],
        ];
    }

    /**
     * @param MiddlewareInterface|RouteParamValidatorInterface|SerializableClosure|object|ServiceReference $dependency
     * @param SplObjectStorage<object,int> $objectIds
     * @param list<object>                  $objects
     * @param array<int,list<string>>       $objectContexts
     * @return array{type:'service',id:string}|array{type:'object',id:int}
     */
    private function encodeDependency(
        object $dependency,
        Router $router,
        SplObjectStorage $objectIds,
        array &$objects,
        array &$objectContexts,
        string $context,
    ): array {
        if ($dependency instanceof ServiceReference) {
            return ['type' => 'service', 'id' => $router->getServiceId($dependency)];
        }
        if (!$objectIds->contains($dependency)) {
            $id = count($objects);
            $objectIds[$dependency] = $id;
            $objects[] = $dependency;
        } else {
            $id = $objectIds[$dependency];
        }
        $objectContexts[$id][] = $context;
        return ['type' => 'object', 'id' => $id];
    }

    /**
     * @param list<object>            $objects
     * @param array<int,list<string>> $contexts
     */
    private function serializeObjectPool(array $objects, array $contexts): string
    {
        $errors = [];
        foreach ($objects as $id => $object) {
            try {
                $this->serializeValue([$object]);
            } catch (Throwable $exception) {
                $errors[] = sprintf(
                    '%s used by %s: %s',
                    $object::class,
                    implode(', ', array_unique($contexts[$id] ?? ['unknown route'])),
                    $exception->getMessage(),
                );
            }
        }
        if ($errors !== []) {
            throw new RouteCacheCompilationException(
                "Route cache cannot serialize these route dependencies:\n- " . implode("\n- ", $errors),
            );
        }

        try {
            return $this->serializeValue($objects);
        } catch (Throwable $exception) {
            throw new RouteCacheCompilationException(
                'Unable to serialize the route dependency object pool: ' . $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    private function serializeValue(mixed $value): string
    {
        set_error_handler(
            static function (int $severity, string $message, string $file, int $line): never {
                throw new ErrorException($message, 0, $severity, $file, $line);
            },
        );
        try {
            return serialize($value);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private function hydrate(Router $router, array $data): void
    {
        $objects = $this->unserializeObjectPool($data['objects'] ?? null);
        $definitions = $data['routes'] ?? null;
        if (!is_array($definitions)) {
            throw new UnexpectedValueException('Compiled route definitions are missing.');
        }

        /** @var array<int,Route> $routes */
        $routes = [];
        $remaining = $definitions;
        while ($remaining !== []) {
            $progress = false;
            foreach ($remaining as $id => $definition) {
                if (!is_array($definition)) {
                    throw new UnexpectedValueException('A compiled route definition is invalid.');
                }
                $type = $definition['type'] ?? null;
                $method = RequestMethod::from((string) ($definition['method'] ?? ''));
                $path = (string) ($definition['path'] ?? '');

                if ($type === 'route') {
                    $route = Route::create($method, $path, $this->decodeHandler($definition['handler'] ?? null, $objects));
                } elseif ($type === 'localized') {
                    $parentId = $definition['parent'] ?? null;
                    if (!is_int($parentId) || !isset($routes[$parentId])) {
                        continue;
                    }
                    $route = LocalizedRoute::createLocalized(
                        $method,
                        $path,
                        (string) ($definition['locale'] ?? ''),
                        $routes[$parentId],
                    );
                } elseif ($type === 'alias') {
                    $targetId = $definition['redirectTo'] ?? null;
                    if (!is_int($targetId) || !isset($routes[$targetId])) {
                        continue;
                    }
                    $route = AliasRoute::createAlias($method, $path, $routes[$targetId]);
                } else {
                    throw new UnexpectedValueException(sprintf('Unknown compiled route type "%s".', (string) $type));
                }

                $route->setName((string) ($definition['name'] ?? ''));
                foreach (($definition['middleware'] ?? []) as $dependency) {
                    $route->middleware($this->decodeDependency($dependency, $objects, $router, MiddlewareInterface::class));
                }
                foreach (($definition['validators'] ?? []) as $name => $validators) {
                    foreach ($validators as $dependency) {
                        $route->param(
                            (string) $name,
                            $this->decodeDependency(
                                $dependency,
                                $objects,
                                $router,
                                RouteParamValidatorInterface::class,
                            ),
                        );
                    }
                }
                $routes[(int) $id] = $route;
                unset($remaining[$id]);
                $progress = true;
            }
            if (!$progress) {
                throw new UnexpectedValueException('Compiled route relationships contain an unresolved cycle.');
            }
        }

        foreach ($definitions as $id => $definition) {
            $localized = [];
            foreach (($definition['localized'] ?? []) as $locale => $localizedId) {
                if (!is_int($localizedId) || !isset($routes[$localizedId])) {
                    throw new UnexpectedValueException('A compiled localized route reference is invalid.');
                }
                $localized[(string) $locale] = $routes[$localizedId];
            }
            $routes[(int) $id]->restoreLocalization(
                isset($definition['locale']) && is_string($definition['locale']) ? $definition['locale'] : null,
                $localized,
            );
        }

        $tree = $this->decodeNode($data['tree'] ?? null, $routes);
        if (!is_array($tree)) {
            throw new UnexpectedValueException('The compiled route tree root must be an array.');
        }
        $named = [];
        foreach (($data['named'] ?? []) as $name => $id) {
            if (!is_int($id) || !isset($routes[$id])) {
                throw new UnexpectedValueException('A compiled named route reference is invalid.');
            }
            $named[(string) $name] = $routes[$id];
        }

        ksort($routes);
        $router->restoreCompiledRoutes($tree, $named, array_values($routes));
    }

    /**
     * @param list<object> $objects
     * @return callable|array{0:class-string|object,1:string}
     */
    private function decodeHandler(mixed $definition, array $objects): callable|array
    {
        if (!is_array($definition)) {
            throw new UnexpectedValueException('A compiled route handler is invalid.');
        }
        return match ($definition['type'] ?? null) {
            'callable' => (string) ($definition['value'] ?? ''),
            'class_method' => [(string) ($definition['class'] ?? ''), (string) ($definition['method'] ?? '')],
            'object_method' => [
                $this->decodeObjectDependency($definition['object'] ?? null, $objects),
                (string) ($definition['method'] ?? ''),
            ],
            'closure' => $this->decodeClosure($definition['dependency'] ?? null, $objects),
            default => throw new UnexpectedValueException('Unknown compiled route handler type.'),
        };
    }

    /**
     * @param list<object> $objects
     */
    private function decodeClosure(mixed $definition, array $objects): callable
    {
        $closure = $this->decodeObjectDependency($definition, $objects);
        if (!$closure instanceof SerializableClosure) {
            throw new UnexpectedValueException('A compiled closure handler has an invalid object.');
        }
        return $closure->getClosure();
    }

    /**
     * @param list<object> $objects
     * @param class-string $expectedType
     */
    private function decodeDependency(mixed $definition, array $objects, Router $router, string $expectedType): object
    {
        if (!is_array($definition)) {
            throw new UnexpectedValueException('A compiled route dependency is invalid.');
        }
        if (($definition['type'] ?? null) === 'service') {
            return $router->resolveServiceId((string) ($definition['id'] ?? ''), $expectedType);
        }
        $object = $this->decodeObjectDependency($definition, $objects);
        if (!$object instanceof $expectedType) {
            throw new UnexpectedValueException(
                sprintf('A compiled route dependency must implement %s; got %s.', $expectedType, $object::class),
            );
        }
        return $object;
    }

    /**
     * @param list<object> $objects
     */
    private function decodeObjectDependency(mixed $definition, array $objects): object
    {
        if (!is_array($definition) || ($definition['type'] ?? null) !== 'object') {
            throw new UnexpectedValueException('A compiled object dependency is invalid.');
        }
        $id = $definition['id'] ?? null;
        if (!is_int($id) || !isset($objects[$id])) {
            throw new UnexpectedValueException('A compiled object dependency ID is invalid.');
        }
        return $objects[$id];
    }

    /**
     * @param array<int,Route> $routes
     */
    private function decodeNode(mixed $definition, array $routes): mixed
    {
        if (!is_array($definition)) {
            throw new UnexpectedValueException('A compiled matcher node is invalid.');
        }
        return match ($definition['type'] ?? null) {
            'route' => $this->decodeRouteNode($definition, $routes),
            'parameter' => new RouteParameter(
                (string) ($definition['name'] ?? ''),
                $this->decodeArrayNode($definition['routes'] ?? null, $routes),
                optional: (bool) ($definition['optional'] ?? false),
                default: isset($definition['default']) ? (string) $definition['default'] : null,
            ),
            'array' => $this->decodeArrayChildren($definition['children'] ?? null, $routes),
            default => throw new UnexpectedValueException('Unknown compiled matcher node type.'),
        };
    }

    /** @param array<int,Route> $routes */
    private function decodeRouteNode(array $definition, array $routes): Route
    {
        $id = $definition['id'] ?? null;
        if (!is_int($id) || !isset($routes[$id])) {
            throw new UnexpectedValueException('A compiled matcher route ID is invalid.');
        }
        return $routes[$id];
    }

    /** @param array<int,Route> $routes */
    private function decodeArrayNode(mixed $definition, array $routes): array
    {
        $node = $this->decodeNode($definition, $routes);
        if (!is_array($node)) {
            throw new UnexpectedValueException('A compiled parameter child node must be an array.');
        }
        return $node;
    }

    /** @param array<int,Route> $routes */
    private function decodeArrayChildren(mixed $children, array $routes): array
    {
        if (!is_array($children)) {
            throw new UnexpectedValueException('Compiled matcher children must be an array.');
        }
        $decoded = [];
        foreach ($children as $key => $child) {
            $decoded[(string) $key] = $this->decodeNode($child, $routes);
        }
        return $decoded;
    }

    /**
     * @return list<object>
     */
    private function unserializeObjectPool(mixed $payload): array
    {
        if (!is_string($payload)) {
            throw new UnexpectedValueException('The compiled route object pool is missing.');
        }
        set_error_handler(
            static function (int $severity, string $message, string $file, int $line): never {
                throw new ErrorException($message, 0, $severity, $file, $line);
            },
        );
        try {
            $objects = unserialize($payload, ['allowed_classes' => true]);
        } finally {
            restore_error_handler();
        }
        if (!is_array($objects) || !array_is_list($objects)) {
            throw new UnexpectedValueException('The compiled route object pool is invalid.');
        }
        foreach ($objects as $object) {
            if (!is_object($object)) {
                throw new UnexpectedValueException('The compiled route object pool contains a non-object value.');
            }
        }
        return $objects;
    }

    /**
     * @return array<string,int|null>
     */
    private function createSourceManifest(): array
    {
        $files = [];
        foreach ($this->routeSources as $source) {
            if (is_dir($source)) {
                $matches = glob(rtrim($source, '/\\') . DIRECTORY_SEPARATOR . '*.php') ?: [];
                array_push($files, ...$matches);
            } else {
                $files[] = $source;
            }
        }
        foreach ($this->controllerSources as $source) {
            if (!is_dir($source)) {
                $files[] = $source;
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source));
            $matches = new RegexIterator($iterator, '/^.+\.php$/i', RegexIterator::GET_MATCH);
            foreach ($matches as [$file]) {
                $files[] = $file;
            }
        }

        $manifest = [];
        foreach (array_unique($files) as $file) {
            $path = realpath($file) ?: $file;
            $mtime = @filemtime($path);
            $manifest[$path] = $mtime === false ? null : $mtime;
        }
        ksort($manifest);
        return $manifest;
    }

    private function writeAtomically(string $contents): void
    {
        $directory = dirname($this->file);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RouteCacheCompilationException(sprintf('Unable to create route cache directory "%s".', $directory));
        }

        $lockFile = $this->file . '.lock';
        $lock = fopen($lockFile, 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RouteCacheCompilationException(sprintf('Unable to lock route cache "%s".', $this->file));
        }

        $temporaryFile = $this->file . '.tmp';
        try {
            $bytes = file_put_contents($temporaryFile, $contents);
            if ($bytes !== strlen($contents)) {
                throw new RouteCacheCompilationException(sprintf('Unable to write complete route cache "%s".', $temporaryFile));
            }
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($temporaryFile, true);
            }
            $probe = require $temporaryFile;
            if (!is_array($probe) || ($probe['version'] ?? null) !== self::FORMAT_VERSION) {
                throw new RouteCacheCompilationException('Generated route cache failed its format validation.');
            }
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($this->file, true);
            }
            if (!rename($temporaryFile, $this->file)) {
                throw new RouteCacheCompilationException(sprintf('Unable to publish route cache "%s".', $this->file));
            }
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($this->file, true);
            }
        } finally {
            if (is_file($temporaryFile)) {
                @unlink($temporaryFile);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
