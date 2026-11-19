<?php
/** Bootstrap and context router. */

defined('ABSPATH') || exit;

define('RAPIDCHAT_DB_VERSION', '1');
define('RAPIDCHAT_CAPS_VERSION', '1');
define('RAPIDCHAT_COOKIE', 'rapidchat_token');
define('RAPIDCHAT_CAP_OPERATE', 'rapidchat_operate');
define('RAPIDCHAT_CAP_MANAGE', 'rapidchat_manage');
define('RAPIDCHAT_MAX_MESSAGE', 4000);
define('RAPIDCHAT_LIST_LIMIT', 50);

define('RAPIDCHAT_PRO_URL', 'https://www.softaculous.com/rapidchat/?from=plugin');
define('RAPIDCHAT_DOCS', 'https://www.softaculous.com/docs/rapidchat/');

/** Check if RapidChat Pro add-on is active. */
function rapidchat_is_pro() {
	return (bool) apply_filters('rapidchat_is_pro', false);
}

/** Include one or more modules from main/, once each. */
function rapidchat_load(...$modules) {
	foreach ($modules as $module) {
		if (strpos((string) $module, '..') !== false) {
			continue;
		}
		include_once RAPIDCHAT_DIR . '/main/' . $module . '.php';
	}
}

register_activation_hook(RAPIDCHAT_FILE, 'rapidchat_activate');
function rapidchat_activate() {
	rapidchat_load('data', 'install');
	rapidchat_register_tables();
	rapidchat_install();
}

register_deactivation_hook(RAPIDCHAT_FILE, 'rapidchat_deactivate');
function rapidchat_deactivate() {
	wp_unschedule_hook('rapidchat_resolve_country');
}

function rapidchat_update_check() {
	if (get_option('rapidchat_db_version') === RAPIDCHAT_DB_VERSION) {
		return;
	}

	rapidchat_load('data', 'install');
	rapidchat_install();
}

function rapidchat_maybe_sync_capabilities() {
	if (get_option('rapidchat_caps_version') !== RAPIDCHAT_CAPS_VERSION) {
		rapidchat_load('data', 'install');
		rapidchat_register_capabilities();
		update_option('rapidchat_caps_version', RAPIDCHAT_CAPS_VERSION);
	}
}

add_action('plugins_loaded', 'rapidchat_load_plugin');
function rapidchat_load_plugin() {
	rapidchat_load('data');
	rapidchat_register_tables();
	add_action('switch_blog', 'rapidchat_register_tables');

	rapidchat_update_check();

	do_action('rapidchat_loaded');

	rapidchat_register_tables();

	add_shortcode('rapid_chat', 'rapidchat_shortcode');
	add_action('wp_footer', 'rapidchat_render_footer_widget');
	add_action('wp_enqueue_scripts', 'rapidchat_enqueue_front_assets');
	add_action('rest_api_init', 'rapidchat_register_rest_api');
	add_filter('rest_pre_dispatch', 'rapidchat_rest_load_modules', 10, 3);

	add_action('rapidchat_resolve_country', 'rapidchat_cron_resolve_country', 10, 2);

	add_filter('user_has_cap', 'rapidchat_user_has_cap', 10, 4);

	add_action('set_user_role', 'rapidchat_on_user_role_change');
	add_action('add_user_role', 'rapidchat_on_user_role_change');
	add_action('remove_user_role', 'rapidchat_on_user_role_change');
	add_action('delete_user', 'rapidchat_on_user_deleted');

	if (is_admin()) {
		add_filter('plugin_action_links_' . RAPIDCHAT_BASE, 'rapidchat_plugin_action_links');

		add_action('admin_init', 'rapidchat_privacy_policy_content');
		add_filter('wp_privacy_personal_data_exporters', 'rapidchat_register_privacy_exporter');
		add_filter('wp_privacy_personal_data_erasers', 'rapidchat_register_privacy_eraser');

		add_action('admin_menu', 'rapidchat_admin_menu');
		add_action('admin_enqueue_scripts', 'rapidchat_enqueue_admin_assets');
		add_action('admin_enqueue_scripts', 'rapidchat_menu_icon_style');
		add_action('admin_init', 'rapidchat_maybe_sync_capabilities');

		if (get_option('rapidchat_schema_missing')) {
			add_action('admin_notices', 'rapidchat_schema_notice');
		}
	}

	rapidchat_maybe_load_ajax();
}

function rapidchat_user_has_cap($allcaps, $caps, $args, $user) {
	foreach ((array) $caps as $cap) {
		if (!empty($allcaps[$cap]) || strpos((string) $cap, 'rapidchat_') !== 0) {
			continue;
		}

		if (!empty($allcaps[RAPIDCHAT_CAP_MANAGE])) {
			$allcaps[$cap] = true;
		} elseif (!empty($allcaps[RAPIDCHAT_CAP_OPERATE]) && ($cap === 'rapidchat_view' || $cap === 'rapidchat_reply')) {
			$allcaps[$cap] = true;
		}
	}

	return $allcaps;
}

function rapidchat_on_user_role_change($user_id) {
	rapidchat_load('data', 'auth');
	rapidchat_sync_account_role($user_id);
}

function rapidchat_on_user_deleted($user_id) {
	rapidchat_load('data', 'auth');
	rapidchat_sync_account_role($user_id, true);
}

/** Settings and Go Pro shortcuts on the Plugins screen. */
function rapidchat_plugin_action_links($links) {
	$extra = array();

	if (rapidchat_allowed_settings_tabs()) {
		$extra[] = '<a href="' . esc_url(admin_url('admin.php?page=rapidchat-settings')) . '">' . esc_html__('Settings', 'rapidchat') . '</a>';
	}

	if (!rapidchat_is_pro()) {
		$extra[] = '<a href="' . esc_url(RAPIDCHAT_PRO_URL) . '" target="_blank" rel="noopener noreferrer">'
			. esc_html__('Go Pro', 'rapidchat')
			. '</a>';
	}

	return array_merge($extra, $links);
}

function rapidchat_load_engine() {
	rapidchat_load('data', 'auth', 'render', 'engine/events', 'engine/routing', 'engine/message', 'engine/transcript', 'engine/conversation', 'engine/attachment', 'engine/customer', 'engine/thread');
}

function rapidchat_register_rest_api() {
	require_once RAPIDCHAT_DIR . '/main/api/class-guest-controller.php';
	require_once RAPIDCHAT_DIR . '/main/api/class-operator-controller.php';

	$guest = new RapidChat_Guest_Controller();
	$guest->register_routes();

	$operator = new RapidChat_Operator_Controller();
	$operator->register_routes();

	do_action('rapidchat_rest_api_init');
}

/** rest_pre_dispatch runs before the route's permission callback and handler. */
function rapidchat_rest_load_modules($result, $server, $request) {
	if (strpos($request->get_route(), '/rapidchat/') === 0) {
		rapidchat_load_engine();
		rapidchat_load('ajax-guest', 'ajax-operator');
	}

	return $result;
}

/** Every AJAX action this plugin answers, mapped to the module that serves it. */
function rapidchat_ajax_map() {
	return (array) apply_filters('rapidchat_ajax_map', array(
		'rapidchat_authenticate_user'        => 'guest',
		'rapidchat_conversations_user'       => 'guest',
		'rapidchat_conversation_user'        => 'guest',
		'rapidchat_find_conversation'        => 'guest',
		'rapidchat_post_message'             => 'guest',
		'rapidchat_upload_attachment'        => 'guest',
		'rapidchat_rate_conversation'        => 'guest',
		'rapidchat_send_transcript'          => 'guest',
		'rapidchat_destroy_chat_session'     => 'guest',
		'rapidchat_poll'                     => 'guest',
		'rapidchat_admin_login_user'         => 'operator',
		'rapidchat_admin_conversations_user' => 'operator',
		'rapidchat_conversation_admin'       => 'operator',
		'rapidchat_admin_post_message'       => 'operator',
		'rapidchat_admin_send_transcript'    => 'operator',
		'rapidchat_admin_poll'               => 'operator',
		'rapidchat_claim_conversation'       => 'operator',
		'rapidchat_delete_chat'              => 'operator',
		'rapidchat_update_tags'              => 'operator',
		'rapidchat_get_smart_suggestions'    => 'operator',
		'rapidchat_close_conversation'       => 'operator',
	));
}

/** Load only the handler module the incoming AJAX action needs. */
function rapidchat_maybe_load_ajax() {
	if (!wp_doing_ajax()) {
		return;
	}

	$action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- routing only; each handler verifies its own nonce.
	$map    = rapidchat_ajax_map();

	if (!isset($map[$action])) {
		return;
	}

	rapidchat_load_engine();
	rapidchat_load($map[$action] === 'operator' ? 'ajax-operator' : 'ajax-guest');

	if ($action === 'rapidchat_poll' || $action === 'rapidchat_admin_poll') {
		rapidchat_load('poll');
	}
}

function rapidchat_render_footer_widget() {
	if (!get_option('rapidchat_auto_display', 1)) {
		return;
	}

	rapidchat_load('render');
	echo rapidchat_kses(rapidchat_shortcode());
}

/** Shortcode callback, also used by the footer hook. */
function rapidchat_shortcode() {
	static $rendered = false;

	if ($rendered) {
		return '';
	}

	$rendered = true;

	rapidchat_load('data', 'auth', 'render', 'widget');

	ob_start();
	rapidchat_widget_html();

	return ob_get_clean();
}

/** Settings tab => the capability that opens it (and saves it). */
function rapidchat_settings_tabs() {
	$tabs = array(
		'branding'    => array(
			'icon'      => 'dashicons-admin-appearance',
			'label'     => __('Widget Customizer', 'rapidchat'),
			'summary'   => __('How the chat widget looks and what visitors see first.', 'rapidchat'),
			'cap'       => 'rapidchat_manage_settings',
			'render'    => 'rapidchat_settings_tab_branding',
			'save'      => 'rapidchat_handle_settings_save',
			'save_form' => array('rc-customizer-form', __('Save Changes', 'rapidchat')),
		),
		'agents'      => array(
			'icon'    => 'dashicons-groups',
			'label'   => __('Agents', 'rapidchat'),
			'summary' => __('Who answers chats.', 'rapidchat'),
			'cap'     => 'rapidchat_manage_agents',
			'render'  => 'rapidchat_settings_tab_agents_free',
		),
		'canned'      => array(
			'icon'    => 'dashicons-format-chat',
			'label'   => __('Canned Responses', 'rapidchat'),
			'summary' => __('Saved replies agents insert by typing /shortcut.', 'rapidchat'),
			'cap'     => 'rapidchat_manage_flows',
			'render'  => 'rapidchat_settings_tab_canned',
			'save'    => 'rapidchat_handle_canned_save',
		),
		'general'     => array(
			'icon'      => 'dashicons-admin-settings',
			'label'     => __('General & Security', 'rapidchat'),
			'summary'   => __('Who can answer chats, privacy and network settings.', 'rapidchat'),
			'cap'       => 'rapidchat_manage_settings',
			'render'    => 'rapidchat_settings_tab_general',
			'save'      => 'rapidchat_handle_settings_save',
			'save_form' => array('rc-general-form', __('Save Settings', 'rapidchat')),
		),
	);

	return (array) apply_filters('rapidchat_settings_tabs', $tabs);
}

/** Settings tab => the capability it needs. */
function rapidchat_settings_tab_caps() {
	$caps = array();
	foreach (rapidchat_settings_tabs() as $slug => $tab) {
		$caps[$slug] = isset($tab['cap']) ? $tab['cap'] : 'rapidchat_manage_settings';
	}

	return $caps;
}

/** The settings tabs the current user may open, in display order. */
function rapidchat_allowed_settings_tabs() {
	$allowed = array();
	foreach (rapidchat_settings_tab_caps() as $tab => $cap) {
		if (current_user_can($cap)) {
			$allowed[] = $tab;
		}
	}

	return $allowed;
}

function rapidchat_admin_menu() {
	$settings_tabs = rapidchat_allowed_settings_tabs();
	$tab_caps      = rapidchat_settings_tab_caps();
	$settings_cap  = $settings_tabs ? $tab_caps[$settings_tabs[0]] : RAPIDCHAT_CAP_MANAGE;

	add_menu_page(
		__('RapidChat Workspace', 'rapidchat'),
		__('RapidChat', 'rapidchat'),
		'rapidchat_view',
		'rapidchat-inbox',
		'rapidchat_render_inbox_page',
		RAPIDCHAT_URL . '/images/menu-icon.png',
		58
	);

	add_submenu_page(
		'rapidchat-inbox',
		__('Live Inbox', 'rapidchat'),
		__('Live Inbox', 'rapidchat'),
		'rapidchat_view',
		'rapidchat-inbox',
		'rapidchat_render_inbox_page'
	);

	do_action('rapidchat_admin_menu_pages');

	add_submenu_page(
		'rapidchat-inbox',
		__('Chat Settings', 'rapidchat'),
		__('Settings', 'rapidchat'),
		$settings_cap,
		'rapidchat-settings',
		'rapidchat_render_settings_page'
	);

	do_action('rapidchat_admin_menu');
}

function rapidchat_render_inbox_page() {
	if (!current_user_can('rapidchat_view')) {
		wp_die(esc_html__('You do not have permission to access this page.', 'rapidchat'));
	}

	rapidchat_load('data', 'auth', 'render', 'page-inbox');

	rapidchat_inbox_page();
}

function rapidchat_render_settings_page() {
	if (!rapidchat_allowed_settings_tabs()) {
		wp_die(esc_html__('You do not have permission to access this page.', 'rapidchat'));
	}

	rapidchat_load('data', 'install', 'auth', 'render', 'page-settings');
	rapidchat_settings_page();
}

function rapidchat_schema_notice() {
	if (!current_user_can('activate_plugins')) {
		return;
	}

	$missing = (array) get_option('rapidchat_schema_missing');
	if (!$missing) {
		return;
	}

	echo '<div class="notice notice-error"><p><strong>RapidChat:</strong> '
		. esc_html__('the database is missing pieces this version needs, so parts of the plugin will fail. Deactivating and reactivating the plugin will retry the upgrade.', 'rapidchat')
		. '</p><p><code>' . esc_html(implode(', ', $missing)) . '</code></p></div>';
}

function rapidchat_enqueue_front_assets() {
	if (!rapidchat_widget_will_render()) {
		return;
	}

	rapidchat_load('data');

	wp_enqueue_style('rapidchat-style', RAPIDCHAT_CSS . '/style.css', array(), rapidchat_asset_version('css/style.css'));
	wp_add_inline_style('rapidchat-style', '#rapidchat-widget.live-support-chat{' . rapidchat_generate_theme_styles(get_option('rapidchat_primary_color', '#1e3c72')) . '}');
	wp_enqueue_script('rapidchat-widget', RAPIDCHAT_JS . '/chat-widget.js', array('jquery'), rapidchat_asset_version('js/chat-widget.js'), true);

	wp_localize_script('rapidchat-widget', 'rapidchat_ajax', apply_filters('rapidchat_widget_script_data', array(
		'ajax_url'        => admin_url('admin-ajax.php'),
		'rest_url'        => esc_url_raw(rest_url('rapidchat/v1/')),
		'rest_nonce'      => wp_create_nonce('wp_rest'),
		'rapidchat_nonce' => wp_create_nonce('rapidchat_user_nonce'),
		'max_message'     => RAPIDCHAT_MAX_MESSAGE,
		'widget_mode'     => get_option('rapidchat_widget_mode', 'hub'),
		'show_home'       => (int) get_option('rapidchat_show_home_tab', 1),
		'show_help'       => rapidchat_widget_flag('show_help'),
		'show_faqs'       => rapidchat_widget_flag('show_faqs'),
	)));

	do_action('rapidchat_enqueue_widget_assets');
}

/** Only load the front-end bundle on pages that will actually show the widget. */
function rapidchat_widget_will_render() {
	if (get_option('rapidchat_auto_display', 1)) {
		return true;
	}

	$post = get_post();

	return $post instanceof WP_Post && has_shortcode($post->post_content, 'rapid_chat');
}

function rapidchat_asset_version($relative_path) {
	$path  = RAPIDCHAT_DIR . '/' . ltrim($relative_path, '/');
	$mtime = file_exists($path) ? filemtime($path) : 0;

	return $mtime ? RAPIDCHAT_VERSION . '.' . $mtime : RAPIDCHAT_VERSION;
}

function rapidchat_enqueue_admin_assets($hook) {
	if ((strpos((string) $hook, 'rapidchat-inbox') === false && strpos((string) $hook, 'rapidchat') === false) || !current_user_can('rapidchat_view')) {
		return;
	}

	wp_enqueue_style('rapidchat-admin-style', RAPIDCHAT_CSS . '/admin.css', array(), rapidchat_asset_version('css/admin.css'));
	wp_enqueue_script('rapidchat-admin', RAPIDCHAT_JS . '/admin.js', array('jquery'), rapidchat_asset_version('js/admin.js'), true);

	wp_localize_script('rapidchat-admin', 'rapidchat_ajax', apply_filters('rapidchat_admin_script_data', array(
		'ajax_url'        => admin_url('admin-ajax.php'),
		'rest_url'        => esc_url_raw(rest_url('rapidchat/v1/')),
		'rest_nonce'      => wp_create_nonce('wp_rest'),
		'rapidchat_nonce' => wp_create_nonce('rapidchat_admin_nonce'),
		'max_message'     => RAPIDCHAT_MAX_MESSAGE,
		'can_manage'      => current_user_can(RAPIDCHAT_CAP_MANAGE),
	)));

	do_action('rapidchat_enqueue_admin_assets', $hook);
}

function rapidchat_menu_icon_style() {
	wp_add_inline_style('admin-menu', '#toplevel_page_rapidchat-inbox .wp-menu-image img{width:20px;height:20px;padding-top:7px;opacity:1}');
}

/** Suggested text for Settings -> Privacy -> Policy Guide. */
function rapidchat_privacy_policy_content() {
	if (!function_exists('wp_add_privacy_policy_content')) {
		return;
	}

	$content = '<p class="privacy-policy-tutorial">' . esc_html__('RapidChat stores chat data in your own database. Adjust this text to match your settings.', 'rapidchat') . '</p>'
		. '<strong class="privacy-policy-tutorial">' . esc_html__('Suggested text:', 'rapidchat') . '</strong> '
		. '<p>' . esc_html__('When you use the live chat on this site, we store the name and email address you enter, the messages and files you send, your IP address, the page you started the chat from, your browser and operating system, and any rating or feedback you leave. We use this to answer your questions and to email you a transcript if you ask for one.', 'rapidchat') . '</p>'
		. '<p>' . esc_html__('The chat sets a cookie (rapidchat_token) that keeps you signed in to your conversation for up to 30 days, and remembers your name and email address in your browser\'s local storage so you do not have to type them again.', 'rapidchat') . '</p>'
		. '<p>' . esc_html__('If the site owner turns on visitor geolocation, your IP address is sent to ipbase.com to look up your country (ipbase privacy policy: https://ipbase.com/privacy-policy/).', 'rapidchat') . '</p>'
		. '<p>' . esc_html__('Chat records are kept until the site owner deletes them. You can ask for a copy of your chat data, or for it to be erased.', 'rapidchat') . '</p>';

	wp_add_privacy_policy_content('RapidChat', wp_kses_post($content));
}

function rapidchat_register_privacy_exporter($exporters) {
	$exporters['rapidchat'] = array(
		'exporter_friendly_name' => __('RapidChat live chat', 'rapidchat'),
		'callback'               => 'rapidchat_privacy_export_callback',
	);

	return $exporters;
}

function rapidchat_register_privacy_eraser($erasers) {
	$erasers['rapidchat'] = array(
		'eraser_friendly_name' => __('RapidChat live chat', 'rapidchat'),
		'callback'             => 'rapidchat_privacy_erase_callback',
	);

	return $erasers;
}

function rapidchat_privacy_export_callback($email, $page = 1) {
	rapidchat_load('privacy');

	return rapidchat_privacy_export($email, $page);
}

function rapidchat_privacy_erase_callback($email, $page = 1) {
	rapidchat_load('privacy');

	return rapidchat_privacy_erase($email, $page);
}

function rapidchat_cron_resolve_country($account_id, $ip) {
	rapidchat_load('data');
	rapidchat_resolve_country_event($account_id, $ip);
}
