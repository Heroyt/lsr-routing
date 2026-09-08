<?php

declare(strict_types=1);

namespace Lsr\Core\Routing;

use Lsr\Core\Requests\Response;
use Lsr\Enums\RequestMethod;
use Psr\Http\Message\ResponseInterface;

class OptionsRoute extends Route
{
    /**
     * @param non-empty-list<RequestMethod> $allowedMethods
     * @param string[]                      $path
     */
    public function __construct(
        public protected(set) array $allowedMethods,
        array $path = [],
        string $readablePath = '*',
    ) {
        parent::__construct(RequestMethod::OPTIONS, [$this, 'respond']);
        $this->path = $path;
        $this->readablePath = $readablePath;
    }

    /**
     * @param non-empty-list<RequestMethod> $allowedMethods
     * @param string[]                      $path
     */
    public static function createFallback(
        array $allowedMethods,
        array $path = [],
        string $readablePath = '*',
    ): OptionsRoute {
        return new self($allowedMethods, $path, $readablePath);
    }

    public function respond(): ResponseInterface {
        return Response::create(
            headers: [
                'Allow' => implode(', ', array_map(
                    static fn (RequestMethod $method): string => $method->value,
                    $this->allowedMethods,
                )),
            ],
        );
    }
}
