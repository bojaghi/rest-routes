<?php

namespace Bojaghi\RestRoutes;

use Bojaghi\Contract\Container as ContinyContainer;
use Bojaghi\Contract\Module;
use Bojaghi\Helper\Helper;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Controller;
use WP_REST_Request;

class RestRoutes implements Module
{
    private array $callbacks;

    private array|string $config;

    private array $namespaces;

    protected ?ContainerInterface $container;

    public function __construct(array|string $config, ?ContainerInterface $container = null)
    {
        $this->callbacks  = [];
        $this->config     = $config;
        $this->container  = $container;
        $this->namespaces = [];

        add_action('rest_api_init', [$this, 'register']);
        add_filter('rest_dispatch_request', [$this, 'dispatch'], 10, 4);
        add_filter('rest_pre_serve_request', [$this, 'addCorsHeaders'], 10, 4);
    }

    /**
     * Replace each callback
     *
     * @param mixed           $result
     * @param WP_REST_Request $request
     * @param string          $route
     *
     * @return mixed
     */
    public function dispatch(mixed $result, WP_REST_Request $request, string $route): mixed
    {
        if (isset($this->callbacks[$route])) {
            $continySupported = $this->container && in_array(
                    needle: ContinyContainer::class,
                    haystack: class_implements($this->container),
                    strict: true,
                );

            try {
                $callback = $continySupported ?
                    $this->container->parseCallback($this->callbacks[$route]) :
                    $this->container->get($this->config[$route]);

                if (is_callable($callback)) {
                    $result = call_user_func($callback, $request);
                }
            } catch (ContainerExceptionInterface $e) {
                $result = new WP_Error($e->getCode(), $e->getMessage(), ['status' => 500]);
            }
        }

        return $result;
    }

    /**
     * Register api settings
     */
    public function register(): void
    {
        foreach (Helper::loadConfig($this->config) as $config) {
            // When $config is FQCN, and it extends WP_REST_Controller
            if (is_string($config) && class_exists($config) && is_subclass_of($config, WP_REST_Controller::class)) {
                if ($this->container) {
                    try {
                        $instance = $this->container->get($config);
                    } catch (NotFoundExceptionInterface|ContainerExceptionInterface $e) {
                        $instance = null;
                    }
                } else {
                    $instance = new $config();
                }
                if ($instance) {
                    $instance->register_routes();
                }
                continue;
            }

            $config = wp_parse_args($config, [
                'namespace' => '',
                'route'     => '',
                'args'      => '',
            ]);

            $namespace = $config['namespace'];
            $route     = $config['route'];
            $args      = $config['args'];

            if (
                !(is_string($namespace) && $namespace) ||
                !(is_string($route) && $route) ||
                !(is_array($args) && isset($args['callback']))
            ) {
                continue;
            }

            $args = wp_parse_args($args, [
                'methods'             => ['GET'],
                'callback'            => false, // callable
                'permission_callback' => false,
                'args'                => [
                    /* Each field's definition, like:
                    'foo' => [
                        'description' => '', // optional
                        'type' =>'string',   // optional
                        'enum' => [],        // optional
                        'required' => false, // optional
                        'default'  => 'bar', // optional
                        // NOTE: 'validate_callback' is called earlier
                        'validate_callback' => fn($value, WP_REST_Rquest $request, string $key) => true, // optional
                        'sanitize_callback' => fn($value, WP_REST_Rquest $request, string $key) => true, // optional
                    ],
                    */
                ],
            ]);

            // Keep the namespace for CORS header
            $this->namespaces[$namespace] = true;

            // Copy the real callback
            $this->callbacks["/$namespace$route"] = $args['callback'];

            // Substitute the callback
            $args['callback'] = '__return_true';

            /** @see WP_REST_Server::register_route() */
            $registered = register_rest_route($namespace, $route, $args);
            if (!$registered) {
                wp_die(sprintf("Failed to register %s/%s", $namespace, $route));
            }
        }
    }

    /**
     * Add CORS header, if our API is called
     *
     * @param bool             $served
     * @param WP_HTTP_Response $response
     * @param WP_REST_Request  $request
     *
     * @return bool
     */
    public function addCorsHeaders(
        bool             $served,
        WP_HTTP_Response $response,
        WP_REST_Request  $request,
        // WP_REST_Server $server
    ): bool
    {
        $route = ltrim($request->get_route(), '/');

        foreach (array_keys($this->namespaces) as $namespace) {
            if (str_starts_with(haystack: $route, needle: $namespace)) {
                rest_send_cors_headers($response);
                break;
            }
        }

        return $served;
    }
}
