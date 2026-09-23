<?php
/**
 * Bojaghi Project
 *
 * Rest Routes
 *
 * @package Bojaghi\Rest_Routes
 */

declare( strict_types=1 );

namespace Bojaghi\RestRoutes;

use Bojaghi\Contract\Container as Continy_Container;
use Bojaghi\Contract\Module;
use Bojaghi\Helper\Helper;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Rest Routes class
 */
class Rest_Routes implements Module {
	/**
	 * List of callbacks
	 *
	 * @var array<string, string|array|callable>
	 */
	private array $callbacks;

	/**
	 * List of configuration.
	 *
	 * After initialization, it is set to null.
	 *
	 * @var array|string|null
	 */
	private array|string|null $config;

	/**
	 * Namespace of Rest route
	 *
	 * @var array
	 */
	private array $namespaces;

	/**
	 * Container interface to get instances.
	 *
	 * @var ContainerInterface|null
	 */
	protected ?ContainerInterface $container;

	/**
	 * Constructor
	 *
	 * @param array|string            $config    Configuration array of string.
	 * @param ContainerInterface|null $container Container like Continy.
	 */
	public function __construct( array|string $config, ?ContainerInterface $container = null ) {
		$this->callbacks  = array();
		$this->config     = $config;
		$this->container  = $container;
		$this->namespaces = array();

		add_action( 'rest_api_init', array( $this, 'register' ) );
		add_filter( 'rest_dispatch_request', array( $this, 'dispatch' ), 10, 4 );
		add_filter( 'rest_pre_serve_request', array( $this, 'add_cors_headers' ), 10, 4 );
	}

	/**
	 * Replace each callback
	 *
	 * @param mixed           $result  This is filter. The result is pass through.
	 * @param WP_REST_Request $request Request.
	 * @param string          $route   Matched route.
	 *
	 * @return mixed
	 */
	public function dispatch( mixed $result, WP_REST_Request $request, string $route ): mixed {
		if ( isset( $this->callbacks[ $route ] ) ) {
			// @formatter:off
			$container_supported = $this->container && in_array(
				needle: Continy_Container::class,
				haystack: class_implements( $this->container ),
				strict: true,
			);
			// @formatter:on

			try {
				$callback = $container_supported ?
					$this->container->parse_callback( $this->callbacks[ $route ] ) :
					$this->container->get( $this->config[ $route ] );

				if ( is_callable( $callback ) ) {
					$result = call_user_func( $callback, $request );
				}
			} catch ( ContainerExceptionInterface $e ) {
				$result = new WP_Error( $e->getCode(), $e->getMessage(), array( 'status' => 500 ) );
			}
		}

		return $result;
	}

	/**
	 * Register api settings
	 */
	public function register(): void {
		foreach ( Helper::load_config( $this->config ) as $config ) {
			// When $config is FQCN, and it extends WP_REST_Controller.
			if ( is_string( $config ) && class_exists( $config ) && is_subclass_of( $config, WP_REST_Controller::class ) ) {
				if ( $this->container ) {
					try {
						$instance = $this->container->get( $config );
					} catch ( ContainerExceptionInterface $e ) {
						$instance = null;
					}
				} else {
					$instance = new $config();
				}
				if ( $instance ) {
					$instance->register_routes();
				}
				continue;
			}

			$config = wp_parse_args(
				$config,
				array(
					'namespace' => '',
					'route'     => '',
					'args'      => '',
				)
			);

			$namespace = $config['namespace'];
			$route     = $config['route'];
			$args      = $config['args'];

			if (
				! ( is_string( $namespace ) && $namespace ) ||
				! ( is_string( $route ) && $route ) ||
				! ( is_array( $args ) && isset( $args['callback'] ) )
			) {
				continue;
			}

			$args = wp_parse_args(
				$args,
				array(
					'methods'             => array( 'GET' ),
					'callback'            => false, // callable.
					'permission_callback' => false,
					'args'                => array(
						// @fomatter:off
						// phpcs:ignore
						/*
						Each field's definition, like:
						'foo' => array(
							'description' => '', // optional
							'type' =>'string',   // optional
							'enum' => [],        // optional
							'required' => false, // optional
							'default'  => 'bar', // optional
							// NOTE: 'validate_callback' is called earlier
							'validate_callback' => fn($value, WP_REST_Rquest $request, string $key) => true, // optional
							'sanitize_callback' => fn($value, WP_REST_Rquest $request, string $key) => true, // optional
						),
						*/
						// @formatter:on
					),
				)
			);

			// Keep the namespace for CORS header.
			$this->namespaces[ $namespace ] = true;

			// Copy the real callback.
			// phpcs:ignore
			// @formatter:off
			$this->callbacks[ "/$namespace$route" ] = $args['callback'];
			// phpcs:ignore
			// @formatter:on

			// Substitute the callback.
			$args['callback'] = '__return_true';

			/**
			 * Rest route registration
			 *
			 * @see WP_REST_Server::register_route()
			 */
			$registered = register_rest_route( $namespace, $route, $args );
			if ( ! $registered ) {
				wp_die( esc_html( sprintf( 'Failed to register %s/%s', $namespace, $route ) ) );
			}
		}
	}

	/**
	 * Add CORS header, if our API is called
	 *
	 * @param bool             $served   Whether the request has already been served. Default false.
	 * @param WP_HTTP_Response $response Result to send to the client. Usually a `WP_REST_Response`.
	 * @param WP_REST_Request  $request  Request used to generate the response.
	 * @param WP_REST_Server   $server   Server instance.
	 *
	 * @return bool
	 */
	public function add_cors_headers(
		bool $served,
		WP_HTTP_Response $response,
		WP_REST_Request $request,
		WP_REST_Server $server,
	): bool {
		$route = ltrim( $request->get_route(), '/' );

		foreach ( array_keys( $this->namespaces ) as $namespace ) {
			if ( str_starts_with( haystack: $route, needle: $namespace ) ) {
				rest_send_cors_headers( $response );
				break;
			}
		}

		return $served;
	}
}
