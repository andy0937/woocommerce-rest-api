<?php
/**
 * REST Controller
 *
 * It's required to follow "Controller Classes" guide before extending this class:
 * <https://developer.wordpress.org/rest-api/extending-the-rest-api/controller-classes/>
 *
 * @class   \WC_REST_Controller
 * @see     https://developer.wordpress.org/rest-api/extending-the-rest-api/controller-classes/
 * @package Automattic/WooCommerce/RestApi
 */

namespace Automattic\WooCommerce\RestApi\Controllers\Version4;

defined( 'ABSPATH' ) || exit;

use \WP_REST_Controller;
use Automattic\WooCommerce\RestApi\Controllers\Version4\Utilities\Permissions;
use Automattic\WooCommerce\RestApi\Controllers\Version4\Utilities\BatchTrait;

/**
 * Abstract Rest Controller Class
 *
 * @package Automattic/WooCommerce/RestApi
 * @extends  WP_REST_Controller
 * @version  2.6.0
 */
abstract class AbstractController extends WP_REST_Controller {
	use BatchTrait;

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wc/v4';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = '';

	/**
	 * Permission to check.
	 *
	 * @var string
	 */
	protected $resource_type = '';

	/**
	 * Register route for items requests.
	 *
	 * @param array $methods Supported methods. read, create.
	 */
	protected function register_items_route( $methods = [ 'read', 'create' ] ) {
		$routes           = [];
		$routes['schema'] = [ $this, 'get_public_item_schema' ];

		if ( in_array( 'read', $methods, true ) ) {
			$routes[] = array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'                => $this->get_collection_params(),
			);
		}

		if ( in_array( 'create', $methods, true ) ) {
			$routes[] = array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_item' ),
				'permission_callback' => array( $this, 'create_item_permissions_check' ),
				'args'                => $this->get_endpoint_args_for_item_schema( \WP_REST_Server::CREATABLE ),
			);
		}

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			$routes,
			true
		);
	}

	/**
	 * Register route for item create/get/delete/update requests.
	 *
	 * @param array $methods Supported methods. read, create.
	 */
	protected function register_item_route( $methods = [ 'read', 'edit', 'delete' ] ) {
		$routes           = [];
		$routes['schema'] = [ $this, 'get_public_item_schema' ];
		$routes['args']   = [
			'id' => [
				'description' => __( 'Unique identifier for the resource.', 'woocommerce-rest-api' ),
				'type'        => 'integer',
			],
		];

		if ( in_array( 'read', $methods, true ) ) {
			$routes[] = array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'get_item_permissions_check' ),
				'args'                => array(
					'context' => $this->get_context_param(
						array(
							'default' => 'view',
						)
					),
				),
			);
		}

		if ( in_array( 'edit', $methods, true ) ) {
			$routes[] = array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_item' ),
				'permission_callback' => array( $this, 'update_item_permissions_check' ),
				'args'                => $this->get_endpoint_args_for_item_schema( \WP_REST_Server::EDITABLE ),
			);
		}

		if ( in_array( 'delete', $methods, true ) ) {
			$routes[] = array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_item' ),
				'permission_callback' => array( $this, 'delete_item_permissions_check' ),
				'args'                => array(
					'force' => array(
						'default'     => false,
						'description' => __( 'Whether to bypass trash and force deletion.', 'woocommerce-rest-api' ),
						'type'        => 'boolean',
					),
				),
			);
		}

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			$routes,
			true
		);
	}

	/**
	 * Add the schema from additional fields to an schema array.
	 *
	 * @param array $schema Schema array.
	 * @return array
	 */
	protected function add_additional_fields_schema( $schema ) {
		$schema               = parent::add_additional_fields_schema( $schema );
		$object_type          = $schema['title'];
		$schema['properties'] = apply_filters( 'woocommerce_rest_' . $object_type . '_schema', $schema['properties'] );
		return $schema;
	}



	/**
	 * Check whether a given request has permission to read webhooks.
	 *
	 * @param  \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|boolean
	 */
	public function get_items_permissions_check( $request ) {
		$permission = Permissions::user_can_list( $this->get_item_title() );

		if ( false === $permission ) {
			return new \WP_Error( 'woocommerce_rest_cannot_view', __( 'Sorry, you cannot list resources.', 'woocommerce-rest-api' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return $permission;
	}

	/**
	 * Check if a given request has access create webhooks.
	 *
	 * @param  \WP_REST_Request $request Full details about the request.
	 *
	 * @return bool|\WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		$permission = Permissions::user_can_create( $this->get_item_title() );

		if ( false === $permission ) {
			return new \WP_Error( 'woocommerce_rest_cannot_create', __( 'Sorry, you are not allowed to create resources.', 'woocommerce-rest-api' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return $permission;
	}

	/**
	 * Check if a given request has access to read a webhook.
	 *
	 * @param  \WP_REST_Request $request Full details about the request.
	 * @return \WP_Error|boolean
	 */
	public function get_item_permissions_check( $request ) {
		$id         = $request->get_param( 'id' );
		$permission = Permissions::user_can_read( $this->get_item_title(), $id );

		if ( false === $permission ) {
			return new \WP_Error( 'woocommerce_rest_cannot_view', __( 'Sorry, you cannot view this resource.', 'woocommerce-rest-api' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return $permission;
	}

	/**
	 * Check if a given request has access update a webhook.
	 *
	 * @param  \WP_REST_Request $request Full details about the request.
	 *
	 * @return bool|\WP_Error
	 */
	public function update_item_permissions_check( $request ) {
		$id         = $request->get_param( 'id' );
		$permission = Permissions::user_can_edit( $this->get_item_title(), $id );

		if ( false === $permission ) {
			return new \WP_Error( 'woocommerce_rest_cannot_edit', __( 'Sorry, you are not allowed to edit this resource.', 'woocommerce-rest-api' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return $permission;
	}

	/**
	 * Check if a given request has access delete a webhook.
	 *
	 * @param  \WP_REST_Request $request Full details about the request.
	 *
	 * @return bool|\WP_Error
	 */
	public function delete_item_permissions_check( $request ) {
		$id         = $request->get_param( 'id' );
		$permission = Permissions::user_can_delete( $this->get_item_title(), $id );

		if ( false === $permission ) {
			return new \WP_Error( 'woocommerce_rest_cannot_delete', __( 'Sorry, you are not allowed to delete this resource.', 'woocommerce-rest-api' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return $permission;
	}

	/**
	 * Check if a given request has access batch create, update and delete items.
	 *
	 * @param  \WP_REST_Request $request Full details about the request.
	 *
	 * @return bool|\WP_Error
	 */
	public function batch_items_permissions_check( $request ) {
		$permission = Permissions::user_can_batch( $this->get_item_title() );

		if ( false === $permission ) {
			return new \WP_Error( 'woocommerce_rest_cannot_batch', __( 'Sorry, you are not allowed to batch manipulate this resource.', 'woocommerce-rest-api' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return $permission;
	}

	/**
	 * Get context for the request.
	 *
	 * @param  \WP_REST_Request $request Full details about the request.
	 * @return string
	 */
	protected function get_request_context( $request ) {
		return ! empty( $request['context'] ) ? $request['context'] : 'view';
	}

	/**
	 * Prepare a single item for response.
	 *
	 * @param mixed            $item Object used to create response.
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response $response Response data.
	 */
	public function prepare_item_for_response( $item, $request ) {
		try {
			$context  = $this->get_request_context( $request );
			$fields   = $this->get_fields_for_response( $request );
			$data     = $this->get_data_for_response( $item, $request );
			$data     = array_intersect_key( $data, array_flip( $fields ) );
			$data     = $this->add_additional_fields_to_object( $data, $request );
			$data     = $this->filter_response_by_context( $data, $context );
			$response = rest_ensure_response( $data );
			$response->add_links( $this->prepare_links( $item, $request ) );
		} catch ( \WC_REST_Exception $e ) {
			$response = rest_ensure_response( new \WP_Error( $e->getErrorCode(), $e->getMessage(), array( 'status' => $e->getCode() ) ) );
		}

		/**
		 * Filter object returned from the REST API.
		 *
		 * @param \WP_REST_Response $response The response object.
		 * @param mixed             $item     Object used to create response.
		 * @param \WP_REST_Request  $request  Request object.
		 */
		return apply_filters( 'woocommerce_rest_prepare_' . $this->get_hook_suffix(), $response, $item, $request );
	}

	/**
	 * Return suffix for item action hooks.
	 *
	 * @return string
	 */
	protected function get_item_title() {
		$schema = $this->get_item_schema();
		return $schema['title'];
	}

	/**
	 * Return suffix for item action hooks.
	 *
	 * @return string
	 */
	protected function get_hook_suffix() {
		return $this->get_item_title();
	}

	/**
	 * Get data for this object in the format of this endpoint's schema.
	 *
	 * @param mixed            $object Object to prepare.
	 * @param \WP_REST_Request $request Request object.
	 * @return mixed Array of data in the correct format.
	 */
	protected function get_data_for_response( $object, $request ) {
		return $object;
	}

	/**
	 * Prepare links for the request.
	 *
	 * @param mixed            $item Object to prepare.
	 * @param \WP_REST_Request $request Request object.
	 * @return array
	 */
	protected function prepare_links( $item, $request ) {
		return array();
	}
}
