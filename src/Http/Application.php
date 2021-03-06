<?php declare(strict_types=1);

namespace Igni\Http;

use FastRoute\DataGenerator\GroupCountBased as StandardDataGenerator;
use FastRoute\RouteParser\Std as StandardRouteParser;
use Igni\Application\Application as AbstractApplication;
use Igni\Application\Controller\ControllerAggregate as AbstractControllerAggregate;
use Igni\Application\Exception\ApplicationException;
use Igni\Container\DependencyResolver;
use Igni\Http\Controller\ControllerAggregate;
use Igni\Http\Exception\HttpModuleException;
use Igni\Http\Middleware\ErrorMiddleware;
use Igni\Http\Server\OnRequest;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zend\Diactoros\Response\SapiEmitter;
use Zend\Stratigility\Middleware\CallableMiddlewareDecorator;
use Zend\Stratigility\MiddlewarePipe;
use Throwable;

/**
 * @see \Igni\Application\Application
 *
 * @package Igni\Http
 */
class Application
    extends AbstractApplication
    implements MiddlewareAggregate, MiddlewareInterface, RequestHandlerInterface, OnRequest {

    /**
     * @var Router
     */
    private $router;

    /**
     * @var ControllerAggregate
     */
    private $controllerAggregate;

    /**
     * @var DependencyResolver
     */
    private $resolver;

    /**
     * @var string|MiddlewareInterface[]
     */
    private $middlewares = [];

    /**
     * @var MiddlewarePipe
     */
    private $pipeline;

    /**
     * Application constructor.
     *
     * @param ContainerInterface|null $container
     */
    public function __construct(ContainerInterface $container = null)
    {
        parent::__construct($container);

        $this->router = new Router(new StandardRouteParser(), new StandardDataGenerator());
        $this->resolver = new DependencyResolver($this->serviceLocator);
        $this->controllerAggregate = new ControllerAggregate($this->router);
    }

    /**
     * While testing call this method before handle method.
     */
    public function startup(): void
    {
        $this->initialize();
        $this->handleOnBootListeners();
    }

    /**
     * While testing, call this method after handle method.
     */
    public function shutdown(): void
    {
        $this->handleOnShutDownListeners();
    }

    /**
     * Startups and run application with/or without dedicated server.
     * Once application is run it will listen to incoming http requests,
     * and takes care of the entire request flow process.
     *
     * @param Server|null $server
     */
    public function run(Server $server = null): void
    {
        $this->startup();

        if ($server) {
            $server->addListener($this);
            $server->start();
        } else {
            $response = $this->handle(ServerRequest::fromGlobals());
            $emitter = new SapiEmitter();
            $emitter->emit($response);
            if ($response instanceof Response) {
                $response->end();
            }
        }

        $this->shutdown();
    }

    /**
     * Registers PSR-15 compatible middelware.
     * Middleware can be either callable object which accepts PSR-7 server request interface and returns
     * response interface, or just class name that implements psr-15 middleware or its instance.
     *
     * @param MiddlewareInterface|callable $middleware
     */
    public function use($middleware): void
    {
        if (!is_subclass_of($middleware, MiddlewareInterface::class)) {
            if (!is_callable($middleware)) {
                throw new ApplicationException('Middleware must be either class or object that implements ' . MiddlewareInterface::class);
            }

            $middleware = new CallableMiddlewareDecorator($middleware);
        }

        $this->middlewares[] = $middleware;
    }

    /**
     * Handles request flow process.
     *
     * @see MiddlewareInterface::process()
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $next
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $next): ResponseInterface
    {
        /** @var Route $route */
        $route = $this->router->findRoute(
            $request->getMethod(),
            $request->getUri()->getPath()
        );

        $controller = $route->getDelegator();

        if ($request instanceof ServerRequest) {
            $request = $request->withAttributes($route->getAttributes());
        }

        if (is_string($controller) &&
            class_exists($controller) &&
            in_array(Controller::class, class_implements($controller))
        ) {
            /** @var Controller $instance */
            $instance = $this->dependencyResolver->resolve($controller);
            return $instance($request);
        }

        if (is_callable($controller)) {
            $response = $controller($request);
            if (!$response instanceof ResponseInterface) {
                throw HttpModuleException::controllerMustReturnValidResponse();
            }

            return $response;
        }

        throw HttpModuleException::couldNotRetrieveControllerForRoute($route->getExpression());
    }

    /**
     * Runs application listeners and handles request flow process.
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->handleOnRunListeners();
        $response = $this->getMiddlewarePipe()->handle($request);
        return $response;
    }

    /**
     * Decorator for handle method, used by server instance.
     * @see Application::handle()
     * @see Server::addListener()
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function onRequest(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request);
    }

    /**
     * Registers new controller that accepts get request
     * when request uri matches passed route pattern.
     *
     * @param string $route
     * @param callable $controller
     */
    public function get(string $route, callable $controller): void
    {
        $this->controllerAggregate->add($controller, Route::get($route));
    }

    /**
     * Registers new controller that accepts post request
     * when request uri matches passed route pattern.
     *
     * @param string $route
     * @param callable $controller
     */
    public function post(string $route, callable $controller): void
    {
        $this->controllerAggregate->add($controller, Route::post($route));
    }

    /**
     * Registers new controller that accepts put request
     * when request uri matches passed route pattern.
     *
     * @param string $route
     * @param callable $controller
     */
    public function put(string $route, callable $controller): void
    {
        $this->controllerAggregate->add($controller, Route::put($route));
    }

    /**
     * Registers new controller that accepts patch request
     * when request uri matches passed route pattern.
     *
     * @param string $route
     * @param callable $controller
     */
    public function patch(string $route, callable $controller): void
    {
        $this->controllerAggregate->add($controller, Route::patch($route));
    }

    /**
     * Registers new controller that accepts delete request
     * when request uri matches passed route pattern.
     *
     * @param string $route
     * @param callable $controller
     */
    public function delete(string $route, callable $controller): void
    {
        $this->controllerAggregate->add($controller, Route::delete($route));
    }

    /**
     * Registers new controller that accepts options request
     * when request uri matches passed route pattern.
     *
     * @param string $route
     * @param callable $controller
     */
    public function options(string $route, callable $controller): void
    {
        $this->controllerAggregate->add($controller, Route::options($route));
    }

    public function head(string $route, callable $controller): void
    {
        $this->controllerAggregate->add($controller, Route::head($route));
    }

    /**
     * Returns application's controller aggregate.
     *
     * @return AbstractControllerAggregate
     */
    public function getControllerAggregate(): AbstractControllerAggregate
    {
        return $this->controllerAggregate;
    }

    protected function getMiddlewarePipe(): MiddlewarePipe
    {
        if ($this->pipeline) {
            return $this->pipeline;
        }

        return $this->pipeline = $this->createPipeline();
    }

    private function createPipeline(): MiddlewarePipe
    {
        $pipe = new MiddlewarePipe();
        $pipe->pipe(new ErrorMiddleware(function(Throwable $exception) {
            $this->handleOnErrorListeners($exception);
        }));
        foreach ($this->middlewares as $middleware) {
            if (is_string($middleware)) {
                $middleware = $this->resolver->resolve($middleware);
            }
            $pipe->pipe($middleware);
        }
        $pipe->pipe($this);

        return $pipe;
    }
}
