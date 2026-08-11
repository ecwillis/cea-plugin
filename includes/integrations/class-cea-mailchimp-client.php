<?php
/**
 * Mailchimp Marketing API client.
 *
 * @package CEA_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Makes authenticated server-side requests to Mailchimp Marketing.
 */
final class CEA_Mailchimp_Client {

	/** Cached audience transient key. */
	const AUDIENCE_CACHE_KEY = 'cea_mailchimp_audiences';

	/** Audience cache duration. */
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Tests the connection and refreshes the audience cache.
	 *
	 * @return array<int, array<string, string>>|WP_Error
	 */
	public function test_connection() {
		$root = $this->request( 'GET', '/' );

		if ( is_wp_error( $root ) ) {
			return $root;
		}

		return $this->refresh_audiences();
	}

	/**
	 * Returns cached audiences without initiating a remote request.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function get_cached_audiences() {
		$audiences = get_transient( self::AUDIENCE_CACHE_KEY );

		return is_array( $audiences ) ? $audiences : array();
	}

	/**
	 * Clears cached audience data.
	 *
	 * @return void
	 */
	public static function clear_audience_cache() {
		delete_transient( self::AUDIENCE_CACHE_KEY );
	}

	/**
	 * Fetches and caches audiences available to the configured account.
	 *
	 * @return array<int, array<string, string>>|WP_Error
	 */
	public function refresh_audiences() {
		$response = $this->request(
			'GET',
			'/lists',
			null,
			array(
				'count'  => 1000,
				'fields' => 'lists.id,lists.name',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$audiences = array();
		$lists     = isset( $response['lists'] ) && is_array( $response['lists'] ) ? $response['lists'] : array();

		foreach ( $lists as $list ) {
			if ( ! is_array( $list ) || empty( $list['id'] ) || ! is_scalar( $list['id'] ) ) {
				continue;
			}

			$id = sanitize_key( (string) $list['id'] );

			if ( '' === $id ) {
				continue;
			}

			$audiences[] = array(
				'id'   => $id,
				'name' => isset( $list['name'] ) && is_scalar( $list['name'] )
					? sanitize_text_field( (string) $list['name'] )
					: $id,
			);
		}

		set_transient( self::AUDIENCE_CACHE_KEY, $audiences, self::CACHE_TTL );

		return $audiences;
	}

	/**
	 * Adds or updates an audience member without changing an existing status.
	 *
	 * @param string               $audience_id  Mailchimp audience ID.
	 * @param string               $email        Normalized email address.
	 * @param string               $status_if_new Status assigned only to new contacts.
	 * @param array<string, string> $merge_fields Optional merge fields.
	 * @return array<string, mixed>|WP_Error
	 */
	public function upsert_member( $audience_id, $email, $status_if_new, $merge_fields = array() ) {
		$payload = array(
			'email_address' => $email,
			'status_if_new' => $status_if_new,
		);

		if ( ! empty( $merge_fields ) ) {
			$payload['merge_fields'] = $merge_fields;
		}

		return $this->request(
			'PUT',
			'/lists/' . rawurlencode( $audience_id ) . '/members/' . self::get_subscriber_hash( $email ),
			$payload
		);
	}

	/**
	 * Applies active tags to an audience member.
	 *
	 * @param string             $audience_id Mailchimp audience ID.
	 * @param string             $email       Normalized email address.
	 * @param array<int, string> $tags        Tag names.
	 * @return true|WP_Error
	 */
	public function add_tags( $audience_id, $email, $tags ) {
		if ( empty( $tags ) ) {
			return true;
		}

		$payload = array( 'tags' => array() );

		foreach ( $tags as $tag ) {
			$payload['tags'][] = array(
				'name'   => $tag,
				'status' => 'active',
			);
		}

		$response = $this->request(
			'POST',
			'/lists/' . rawurlencode( $audience_id ) . '/members/' . self::get_subscriber_hash( $email ) . '/tags',
			$payload
		);

		return is_wp_error( $response ) ? $response : true;
	}

	/**
	 * Calculates Mailchimp's subscriber identifier.
	 *
	 * @param string $email Subscriber email address.
	 * @return string
	 */
	public static function get_subscriber_hash( $email ) {
		return md5( strtolower( trim( (string) $email ) ) );
	}

	/**
	 * Makes one Marketing API request.
	 *
	 * @param string                    $method HTTP method.
	 * @param string                    $path   API path beginning with a slash.
	 * @param array<string, mixed>|null $body   Optional JSON body.
	 * @param array<string, mixed>      $query  Optional query arguments.
	 * @return array<string, mixed>|WP_Error
	 */
	public function request( $method, $path, $body = null, $query = array() ) {
		$errors = CEA_Mailchimp_Settings::get_configuration_errors();

		if ( ! empty( $errors ) ) {
			return new WP_Error( 'cea_mailchimp_configuration', reset( $errors ) );
		}

		$method = strtoupper( (string) $method );

		if ( ! in_array( $method, array( 'GET', 'POST', 'PUT', 'PATCH' ), true ) || ! is_string( $path ) || 0 !== strpos( $path, '/' ) || false !== strpos( $path, '..' ) ) {
			return new WP_Error( 'cea_mailchimp_request', __( 'The Mailchimp request is invalid.', 'cea-plugin' ) );
		}

		$prefix = CEA_Mailchimp_Settings::get_server_prefix();
		$url    = 'https://' . $prefix . '.api.mailchimp.com/3.0' . $path;

		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$args = array(
			'method'      => $method,
			'timeout'     => 10,
			'redirection' => 0,
			'headers'     => array(
				'Accept'        => 'application/json',
				'Authorization' => 'Bearer ' . CEA_Mailchimp_Settings::get_api_key(),
			),
		);

		if ( null !== $body ) {
			$encoded = wp_json_encode( $body );

			if ( ! is_string( $encoded ) ) {
				return new WP_Error( 'cea_mailchimp_payload', __( 'The Mailchimp request could not be encoded.', 'cea-plugin' ) );
			}

			$args['headers']['Content-Type'] = 'application/json; charset=utf-8';
			$args['body']                    = $encoded;
			$args['data_format']             = 'body';
		}

		$response = wp_safe_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'cea_mailchimp_network', __( 'Mailchimp could not be reached.', 'cea-plugin' ) );
		}

		$status = wp_remote_retrieve_response_code( $response );

		if ( 200 > $status || 300 <= $status ) {
			return new WP_Error(
				'cea_mailchimp_http_' . absint( $status ),
				self::get_status_message( $status ),
				array( 'status' => absint( $status ) )
			);
		}

		$response_body = wp_remote_retrieve_body( $response );

		if ( '' === $response_body ) {
			return array();
		}

		$decoded = json_decode( $response_body, true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'cea_mailchimp_response', __( 'Mailchimp returned an unreadable response.', 'cea-plugin' ) );
		}

		return $decoded;
	}

	/**
	 * Returns a safe administrator-facing message for an HTTP status.
	 *
	 * @param int $status HTTP status.
	 * @return string
	 */
	private static function get_status_message( $status ) {
		switch ( absint( $status ) ) {
			case 400:
				return __( 'Mailchimp rejected the submitted contact data.', 'cea-plugin' );
			case 401:
				return __( 'Mailchimp rejected the configured API key.', 'cea-plugin' );
			case 403:
				return __( 'The Mailchimp account cannot perform this action.', 'cea-plugin' );
			case 404:
				return __( 'The configured Mailchimp audience could not be found.', 'cea-plugin' );
			case 429:
				return __( 'Mailchimp is temporarily rate limiting requests.', 'cea-plugin' );
			default:
				return sprintf(
					/* translators: %d: HTTP response status. */
					__( 'Mailchimp returned HTTP status %d.', 'cea-plugin' ),
					absint( $status )
				);
		}
	}
}
