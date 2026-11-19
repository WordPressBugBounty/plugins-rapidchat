<?php
/** REST API controller for guest/visitor endpoints. */

defined('ABSPATH') || exit;

class RapidChat_Guest_Controller extends WP_REST_Controller {
	protected $namespace = 'rapidchat/v1';

	public function register_routes() {
		register_rest_route($this->namespace, '/auth/login', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'login'),
				'permission_callback' => array($this, 'check_guest_nonce'),
				'args'                => array(
					'name'  => array('required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
					'email' => array('required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_email'),
				),
			),
		));

		register_rest_route($this->namespace, '/auth/logout', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'logout'),
				'permission_callback' => array($this, 'check_guest_auth'),
			),
		));

		register_rest_route($this->namespace, '/conversations', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array($this, 'list_conversations'),
				'permission_callback' => array($this, 'check_guest_auth'),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'find_or_create_conversation'),
				'permission_callback' => array($this, 'check_guest_auth'),
			),
		));

		register_rest_route($this->namespace, '/conversations/(?P<id>[\d]+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array($this, 'get_conversation'),
				'permission_callback' => array($this, 'check_guest_auth'),
				'args'                => array(
					'id' => array('required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'),
				),
			),
		));

		register_rest_route($this->namespace, '/conversations/(?P<id>[\d]+)/messages', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'post_message'),
				'permission_callback' => array($this, 'check_guest_auth'),
				'args'                => array(
					'id'              => array('required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'),
					'msg'             => array('type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field'),
					'attachment_url'  => array('type' => 'string', 'default' => '', 'sanitize_callback' => 'esc_url_raw'),
					'attachment_type' => array('type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_key'),
					'attachment_name' => array('type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field'),
					'attachment_size' => array('type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint'),
				),
			),
		));

		register_rest_route($this->namespace, '/conversations/(?P<id>[\d]+)/rate', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'rate_conversation'),
				'permission_callback' => array($this, 'check_guest_auth'),
				'args'                => array(
					'id'       => array('required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'),
					'rating'   => array('required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'),
					'feedback' => array('type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field'),
				),
			),
		));

		register_rest_route($this->namespace, '/conversations/(?P<id>[\d]+)/transcript', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'send_transcript'),
				'permission_callback' => array($this, 'check_guest_auth'),
				'args'                => array(
					'id' => array('required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'),
				),
			),
		));

		register_rest_route($this->namespace, '/attachments/upload', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'upload_attachment'),
				'permission_callback' => array($this, 'check_guest_auth'),
			),
		));

		register_rest_route($this->namespace, '/poll', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'poll'),
				'permission_callback' => array($this, 'check_guest_auth'),
			),
		));
	}

	/** Every guest route needs the widget's nonce; the session cookie alone is not CSRF protection. */
	public function check_guest_nonce($request) {
		$nonce = $request->get_header('X-RapidChat-Nonce');
		if (!$nonce) {
			$nonce = $request->get_param('rapidchat_nonce');
		}

		if (!is_string($nonce) || !wp_verify_nonce(sanitize_text_field($nonce), 'rapidchat_user_nonce')) {
			return new WP_Error('rapidchat_invalid_nonce', __('Your session has expired. Please reload the page and try again.', 'rapidchat'), array('status' => 403));
		}
		return true;
	}

	public function check_guest_auth($request) {
		$nonce_check = $this->check_guest_nonce($request);
		if (is_wp_error($nonce_check)) {
			return $nonce_check;
		}

		$account = rapidchat_current_account();
		if (!$account) {
			return new WP_Error('rapidchat_unauthorized', __('Your chat session has expired. Please start a new conversation.', 'rapidchat'), array('status' => 401));
		}
		return true;
	}

	public function login($request) {
		$result = rapidchat_customer_identify(array(
			'name'  => $request->get_param('name'),
			'email' => $request->get_param('email'),
			'ip'    => rapidchat_client_ip(),
			'actor' => rapidchat_current_account() ?: array(),
		));

		if (is_wp_error($result)) {
			return $result;
		}

		return rest_ensure_response(array('status' => 'success'));
	}

	public function logout($request) {
		$account = rapidchat_current_account();

		do_action('rapidchat_before_guest_logout', (int) $account['id']);

		rapidchat_conversation_end_session(array('actor' => $account));

		rapidchat_clear_token();

		return rest_ensure_response(array('message' => __('Chat session ended successfully.', 'rapidchat')));
	}

	public function list_conversations($request) {
		$account = rapidchat_current_account();
		$account_id = (int) $account['id'];

		$html = rapidchat_render_guest_conversations($account_id);
		$html = apply_filters('rapidchat_guest_conversations_html', $html, $account_id);

		return rest_ensure_response(array('html' => $html));
	}

	public function get_conversation($request) {
		$result = rapidchat_thread_view(array(
			'conversation_id' => $request->get_param('id'),
			'actor'           => rapidchat_current_account(),
		));

		if (is_wp_error($result)) {
			return $result;
		}

		return rest_ensure_response(array('html' => $result['html'], 'state' => $result['state']));
	}

	public function find_or_create_conversation($request) {
		$result = rapidchat_conversation_find_or_create(array(
			'actor'   => rapidchat_current_account(),
			'team_id' => (int) $request->get_param('team_id'),
		));

		if (is_wp_error($result)) {
			return $result;
		}

		return rest_ensure_response(array('id' => $result['id']));
	}

	public function post_message($request) {
		$result = rapidchat_message_create(array(
			'conversation_id' => $request->get_param('id'),
			'actor'           => rapidchat_current_account(),
			'is_operator'     => false,
			'msg'             => $request->get_param('msg'),
			'attachment_url'  => $request->get_param('attachment_url'),
			'attachment_type' => $request->get_param('attachment_type'),
			'attachment_name' => $request->get_param('attachment_name'),
			'attachment_size' => $request->get_param('attachment_size'),
		));

		if (is_wp_error($result)) {
			return $result;
		}

		$result['message'] = __('Message submitted successfully!', 'rapidchat');

		return rest_ensure_response($result);
	}

	public function upload_attachment($request) {
		$files = $request->get_file_params();

		$result = rapidchat_attachment_store(array(
			'actor' => rapidchat_current_account(),
			'file'  => isset($files['file']) && is_array($files['file']) ? $files['file'] : array(),
		));

		if (is_wp_error($result)) {
			return $result;
		}

		return rest_ensure_response($result);
	}

	public function rate_conversation($request) {
		$result = rapidchat_conversation_rate(array(
			'conversation_id' => $request->get_param('id'),
			'actor'           => rapidchat_current_account(),
			'rating'          => $request->get_param('rating'),
			'feedback'        => $request->get_param('feedback'),
		));

		if (is_wp_error($result)) {
			return $result;
		}

		return rest_ensure_response(array('message' => __('Thank you for your feedback!', 'rapidchat')));
	}

	public function send_transcript($request) {
		$result = rapidchat_conversation_transcript(array(
			'conversation_id' => $request->get_param('id'),
			'actor'           => rapidchat_current_account(),
			'is_operator'     => false,
			'recipient'       => 'self',
		));

		if (is_wp_error($result)) {
			return $result;
		}

		return rest_ensure_response(array('message' => __('Transcript sent to your email!', 'rapidchat')));
	}

	public function poll($request) {
		$account = rapidchat_current_account();

		rapidchat_load('poll');
		$response = rapidchat_build_poll_response($account, false, $request->get_params());
		$response = apply_filters('rapidchat_poll_response', $response, $account);

		$rest_response = rest_ensure_response($response);
		if (!empty($response['fingerprint'])) {
			$rest_response->header('ETag', '"' . $response['fingerprint'] . '"');
		}
		$rest_response->header('Cache-Control', 'no-cache, must-revalidate');

		return $rest_response;
	}
}
