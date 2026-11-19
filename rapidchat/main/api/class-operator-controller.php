<?php
/** REST API controller for operator/admin endpoints. */

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class RapidChat_Operator_Controller extends WP_REST_Controller {
	protected $namespace = 'rapidchat/v1';

	public function register_routes() {
		register_rest_route($this->namespace, '/operator/session', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array($this, 'session'),
				'permission_callback' => array($this, 'check_operator_auth'),
			),
		));

		register_rest_route($this->namespace, '/operator/conversations', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array($this, 'list_conversations'),
				'permission_callback' => array($this, 'check_operator_auth'),
			),
		));

		register_rest_route($this->namespace, '/operator/conversations/(?P<id>[\d]+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array($this, 'get_conversation'),
				'permission_callback' => array($this, 'check_operator_auth'),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array($this, 'delete_conversation'),
				'permission_callback' => array($this, 'check_delete_auth'),
			),
		));

		register_rest_route($this->namespace, '/operator/conversations/(?P<id>[\d]+)/messages', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'post_message'),
				'permission_callback' => array($this, 'check_operator_auth'),
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

		register_rest_route($this->namespace, '/operator/conversations/(?P<id>[\d]+)/close', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'close_conversation'),
				'permission_callback' => array($this, 'check_operator_auth'),
				'args'                => array(
					'id' => array('required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'),
				),
			),
		));

		register_rest_route($this->namespace, '/operator/canned-responses', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array($this, 'get_canned_responses'),
				'permission_callback' => array($this, 'check_operator_auth'),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'save_canned_response'),
				'permission_callback' => array($this, 'check_flows_auth'),
				'args'                => array(
					'shortcut' => array('required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key'),
					'title'    => array('required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
					'content'  => array('required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'),
				),
			),
		));

		register_rest_route($this->namespace, '/operator/canned-responses/(?P<id>[\d]+)', array(
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array($this, 'delete_canned_response'),
				'permission_callback' => array($this, 'check_flows_auth'),
			),
		));

		register_rest_route($this->namespace, '/operator/poll', array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'poll'),
				'permission_callback' => array($this, 'check_operator_auth'),
			),
		));
	}

	/** Route-level gate on one granular capability. */
	private function check_cap($cap) {
		if (!is_user_logged_in() || !rapidchat_user_can($cap)) {
			return new WP_Error('rapidchat_forbidden', __('You are not allowed to do that.', 'rapidchat'), array('status' => 403));
		}
		return true;
	}

	public function check_operator_auth($request) {
		return $this->check_cap('rapidchat_view');
	}

	public function check_flows_auth($request) {
		return $this->check_cap('rapidchat_manage_flows');
	}

	public function check_delete_auth($request) {
		return $this->check_cap('rapidchat_manage_settings');
	}

	public function session($request) {
		$account = rapidchat_operator_account();
		if (!$account) {
			return new WP_Error('rapidchat_no_profile', __('No chat operator profile is available for your account.', 'rapidchat'), array('status' => 403));
		}

		return rest_ensure_response(array(
			'status'     => 'success',
			'full_name'  => $account['full_name'],
			'role'       => $account['role'],
			'can_manage' => current_user_can(RAPIDCHAT_CAP_MANAGE),
			'can_assign' => rapidchat_user_can('rapidchat_assign'),
		));
	}

	public function list_conversations($request) {
		global $wpdb;

		$account = rapidchat_operator_account();
		if (!$account) {
			return new WP_Error('rapidchat_no_profile', __('No chat operator profile.', 'rapidchat'), array('status' => 403));
		}

		$wpdb->update(
			$wpdb->rapidchat_accounts,
			array('status' => 'Waiting', 'last_seen' => current_time('mysql')),
			array('id' => (int) $account['id']),
			array('%s', '%s'),
			array('%d')
		);

		$html = rapidchat_render_admin_conversations($account);

		return rest_ensure_response(array('html' => $html));
	}

	public function get_conversation($request) {
		global $wpdb;

		$account         = rapidchat_operator_account();
		if (!$account) {
			return new WP_Error('rapidchat_no_profile', __('No chat operator profile.', 'rapidchat'), array('status' => 403));
		}

		$operator_id     = (int) $account['id'];
		$conversation_id = $request->get_param('id');

		if (!rapidchat_can_access_conversation($operator_id, $conversation_id, true)) {
			return new WP_Error('rapidchat_forbidden', __('Conversation not found.', 'rapidchat'), array('status' => 403));
		}

		$conversation = $wpdb->get_row($wpdb->prepare(
			'SELECT c.*, a.full_name AS sender_name, a.email AS sender_email, a.ip_address AS sender_ip, a.country AS sender_country, a2.full_name AS receiver_name, a2.email AS receiver_email, a2.ip_address AS receiver_ip, a2.country AS receiver_country FROM %i c JOIN %i a ON a.id = c.account_sender_id JOIN %i a2 ON a2.id = c.account_receiver_id WHERE c.id = %d',
			$wpdb->rapidchat_conversations,
			$wpdb->rapidchat_accounts,
			$wpdb->rapidchat_accounts,
			$conversation_id
		));

		if (!$conversation) {
			return new WP_Error('rapidchat_not_found', __('Conversation not found.', 'rapidchat'), array('status' => 404));
		}

		$assignments  = rapidchat_conversation_assignments($conversation_id);
		$is_available = isset($assignments[$operator_id])
			? (int) $assignments[$operator_id]->is_available
			: (rapidchat_conversation_is_claimed($assignments) ? 2 : 1);

		if ($is_available === 0 || $is_available === 3) {
			$wpdb->query($wpdb->prepare(
				'UPDATE %i SET is_read = 1 WHERE conversation_id = %d AND account_id <> %d AND is_read = 0',
				$wpdb->rapidchat_messages,
				$conversation_id, $operator_id
			));
		}

		$messages     = $wpdb->get_results($wpdb->prepare(
			'SELECT id, account_id, msg, attachment_url, attachment_type, attachment_name, attachment_size, submit_date FROM %i WHERE conversation_id = %d ORDER BY id ASC LIMIT 500',
			$wpdb->rapidchat_messages,
			$conversation_id
		));

		$names        = rapidchat_account_names(wp_list_pluck($messages, 'account_id'));
		$operator_ids = array_map('intval', array_keys($assignments));
		$read_through = rapidchat_conversation_read_through($conversation_id, $operator_id);

		$html = rapidchat_render_admin_conversation($conversation, $messages, $names, $account, $assignments, $operator_ids, $is_available, $read_through);

		return rest_ensure_response(array('html' => $html));
	}

	public function post_message($request) {
		$account = rapidchat_operator_account();
		if (!$account) {
			return new WP_Error('rapidchat_no_profile', __('No chat operator profile.', 'rapidchat'), array('status' => 403));
		}

		$result = rapidchat_message_create(array(
			'conversation_id' => $request->get_param('id'),
			'actor'           => $account,
			'is_operator'     => true,
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

	public function close_conversation($request) {
		$account = rapidchat_operator_account();
		if (!$account) {
			return new WP_Error('rapidchat_no_profile', __('No chat operator profile.', 'rapidchat'), array('status' => 403));
		}

		$result = rapidchat_conversation_close($request->get_param('id'), array('actor' => $account));

		if (is_wp_error($result)) {
			return $result;
		}

		return rest_ensure_response(array('message' => __('Conversation closed.', 'rapidchat')));
	}

	public function get_canned_responses($request) {
		global $wpdb;

		$items = $wpdb->get_results($wpdb->prepare('SELECT id, shortcut, title, content FROM %i ORDER BY shortcut ASC', $wpdb->rapidchat_canned_responses));

		return rest_ensure_response(array('items' => $items ? $items : array()));
	}

	public function save_canned_response($request) {
		global $wpdb;

		$shortcut = sanitize_key($request->get_param('shortcut'));
		$title    = sanitize_text_field($request->get_param('title'));
		$content  = sanitize_textarea_field($request->get_param('content'));
		$id       = (int) $request->get_param('id');

		if ($shortcut === '' || $content === '') {
			return new WP_Error('rapidchat_invalid', __('Shortcut and content are required.', 'rapidchat'), array('status' => 422));
		}

		if ($id > 0) {
			$wpdb->update(
				$wpdb->rapidchat_canned_responses,
				array('shortcut' => $shortcut, 'title' => $title, 'content' => $content),
				array('id' => $id),
				array('%s', '%s', '%s'),
				array('%d')
			);
		} else {
			$wpdb->insert(
				$wpdb->rapidchat_canned_responses,
				array('shortcut' => $shortcut, 'title' => $title, 'content' => $content, 'created_at' => current_time('mysql')),
				array('%s', '%s', '%s', '%s')
			);
		}

		return rest_ensure_response(array('message' => __('Canned response saved.', 'rapidchat')));
	}

	public function delete_canned_response($request) {
		global $wpdb;

		$id    = (int) $request->get_param('id');

		$wpdb->delete($wpdb->rapidchat_canned_responses, array('id' => $id), array('%d'));

		return rest_ensure_response(array('message' => __('Canned response deleted.', 'rapidchat')));
	}

	public function delete_conversation($request) {
		$account = rapidchat_operator_account();
		if (!$account) {
			return new WP_Error('rapidchat_no_profile', __('No chat operator profile.', 'rapidchat'), array('status' => 403));
		}

		$conversation_id = $request->get_param('id');

		if (!rapidchat_operator_can('delete', $conversation_id, $account) || !rapidchat_can_access_conversation((int) $account['id'], $conversation_id, true)) {
			return new WP_Error('rapidchat_forbidden', __('Conversation not found.', 'rapidchat'), array('status' => 403));
		}

		do_action('rapidchat_before_conversation_deleted', $conversation_id);

		if (!rapidchat_delete_conversations(array($conversation_id))) {
			return new WP_Error('rapidchat_delete_failed', __('Delete query failed.', 'rapidchat'), array('status' => 500));
		}

		return rest_ensure_response(array('message' => __('Chat deleted successfully', 'rapidchat')));
	}

	public function poll($request) {
		$account = rapidchat_operator_account();
		if (!$account) {
			return new WP_Error('rapidchat_no_profile', __('No chat operator profile.', 'rapidchat'), array('status' => 403));
		}

		rapidchat_load('poll');
		$response = rapidchat_build_poll_response($account, true, $request->get_params());
		$response = apply_filters('rapidchat_poll_response', $response, $account);

		$rest_response = rest_ensure_response($response);
		if (!empty($response['fingerprint'])) {
			$rest_response->header('ETag', '"' . $response['fingerprint'] . '"');
		}
		$rest_response->header('Cache-Control', 'no-cache, must-revalidate');

		return $rest_response;
	}
}
