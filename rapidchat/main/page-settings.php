<?php

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/** Render the Settings screen. */
function rapidchat_settings_page() {
	$tabs         = rapidchat_settings_tabs();
	$allowed_tabs = rapidchat_allowed_settings_tabs();

	if (!$allowed_tabs) {
		echo '<div class="wrap"><h2>' . esc_html__('Access Denied', 'rapidchat') . '</h2><p>'
			. esc_html__('You do not have permission to access this page.', 'rapidchat') . '</p></div>';
		return;
	}

	$saves = array();
	foreach ($allowed_tabs as $slug) {
		if (!empty($tabs[$slug]['save']) && is_callable($tabs[$slug]['save']) && !in_array($tabs[$slug]['save'], $saves, true)) {
			$saves[] = $tabs[$slug]['save'];
		}
	}
	foreach ($saves as $save) {
		call_user_func($save);
	}

	$current_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : $allowed_tabs[0]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
	if (!in_array($current_tab, $allowed_tabs, true)) {
		$current_tab = $allowed_tabs[0];
	}
	$tab = $tabs[$current_tab];

	$rapidchat_nav = array();
	foreach ($allowed_tabs as $slug) {
		$rapidchat_nav[$slug] = array(
			'icon'   => $tabs[$slug]['icon'],
			'label'  => $tabs[$slug]['label'],
			'url'    => admin_url('admin.php?page=rapidchat-settings&tab=' . $slug),
			'active' => $current_tab === $slug,
		);
	}

	$rapidchat_actions = '';
	if (current_user_can('rapidchat_view')) {
		$rapidchat_actions .= rapidchat_admin_action(__('Open Workspace', 'rapidchat'), admin_url('admin.php?page=rapidchat-inbox'), 'secondary', 'dashicons-format-chat');
	}
	if (!empty($tab['save_form'])) {
		$rapidchat_actions .= '<button type="submit" name="submit" form="' . esc_attr($tab['save_form'][0]) . '" class="rc-save-btn">'
			. esc_html($tab['save_form'][1]) . '</button>';
	}

	rapidchat_admin_shell_open(array(
		'title'   => $tab['label'],
		'nav'     => $rapidchat_nav,
		'actions' => $rapidchat_actions,
	));

	echo '<p class="rc-page-intro">' . esc_html($tab['summary']) . '</p>';

	if (!empty($tab['render']) && is_callable($tab['render'])) {
		call_user_func($tab['render']);
	}

	rapidchat_admin_shell_close();
}

function rapidchat_settings_toggle_row($name, $label, $desc, $checked, $id = '') {
	$id = $id ? $id : 'rc-toggle-' . sanitize_html_class(str_replace('_', '-', $name));
	?>
	<div class="rc-setting-row">
		<div class="rc-setting-row-left">
			<label class="rc-setting-label" for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label>
			<p class="rc-setting-desc"><?php echo esc_html($desc); ?></p>
		</div>
		<div class="rc-setting-row-control">
			<label class="rapidchat-toggle">
				<input type="checkbox" name="<?php echo esc_attr($name); ?>" id="<?php echo esc_attr($id); ?>" value="1" <?php checked($checked); ?>>
				<span class="rapidchat-toggle-track" aria-hidden="true"></span>
			</label>
		</div>
	</div>
	<?php
}

function rapidchat_settings_tab_branding() {
	global $wpdb;
			$widget_mode    = get_option('rapidchat_widget_mode', 'hub');
			$show_home_tab  = (int) get_option('rapidchat_show_home_tab', 1);
			$show_help_tab  = rapidchat_widget_flag('show_help');
			$show_home_faqs = rapidchat_widget_flag('show_faqs');

			$primary_color  = get_option('rapidchat_primary_color', '#1e3c72');
			$widget_pos     = get_option('rapidchat_widget_position', 'right');
			$brand_title    = rapidchat_decode_emoji_option(get_option('rapidchat_brand_title', get_bloginfo('name') . ' ' . __('Support', 'rapidchat')));
			$greeting_title = rapidchat_decode_emoji_option(get_option('rapidchat_greeting_title', __('Hi there 👋', 'rapidchat')));
			$greeting_desc  = rapidchat_decode_emoji_option(get_option('rapidchat_greeting_desc', __('How can we help you today? Our team typically replies in minutes.', 'rapidchat')));
			$action_title   = rapidchat_decode_emoji_option(get_option('rapidchat_home_action_title', __('Send us a message', 'rapidchat')));
			$action_sub     = rapidchat_decode_emoji_option(get_option('rapidchat_home_action_sub', __('We reply in minutes ⚡', 'rapidchat')));
			$offline_msg    = rapidchat_decode_emoji_option(get_option('rapidchat_offline_message', __('Leave your message and email below, and our team will get back to you shortly!', 'rapidchat')));
			$auto_display   = get_option('rapidchat_auto_display', 1);

			$prechat_show_message    = (int) get_option('rapidchat_prechat_show_message', 1);
			$prechat_require_message = (int) get_option('rapidchat_prechat_require_message', 0);
			$prechat_intro_title     = rapidchat_decode_emoji_option(get_option('rapidchat_prechat_intro_title', __('Start a conversation', 'rapidchat')));
			$prechat_intro_text      = rapidchat_decode_emoji_option(get_option('rapidchat_prechat_intro_text', __('Tell us who you are so an agent can pick up right where you left off.', 'rapidchat')));

			$presets = array(
				array('name' => 'Classic Navy',   'color' => '#1e3c72'),
				array('name' => 'Modern Indigo',  'color' => '#4f46e5'),
				array('name' => 'Emerald Green',  'color' => '#059669'),
				array('name' => 'Sunset Coral',   'color' => '#e11d48'),
				array('name' => 'Royal Violet',   'color' => '#7c3aed'),
				array('name' => 'Ocean Cyan',     'color' => '#0891b2'),
				array('name' => 'Dark Slate',     'color' => '#1e293b'),
				array('name' => 'Amber Gold',     'color' => '#d97706'),
			);
		?>
		<div class="rc-customizer-split">
			<div class="rc-customizer-form-col">
				<form method="post" id="rc-customizer-form">
					<?php wp_nonce_field('rapidchat_settings', 'rapidchat_settings_nonce'); ?>
					<input type="hidden" name="rapidchat_tab" value="branding">

					<div class="rc-card">
						<div class="rc-card-header">
							<h3><span class="dashicons dashicons-layout" aria-hidden="true"></span><?php esc_html_e('Widget experience', 'rapidchat'); ?></h3>
							<span class="rc-badge" id="rc-preview-mode-badge"><?php echo $widget_mode === 'direct' ? esc_html__('Direct chat', 'rapidchat') : esc_html__('Hub', 'rapidchat'); ?></span>
						</div>
						<div class="rc-setting-row rc-setting-row--stack">
							<div class="rc-setting-row-left">
								<span class="rc-setting-label"><?php esc_html_e('Experience mode', 'rapidchat'); ?></span>
								<p class="rc-setting-desc"><?php esc_html_e('A direct live chat conversation, or a multi-tab self-service hub with Home and Help.', 'rapidchat'); ?></p>
							</div>
							<div class="rc-setting-row-control">
								<div class="rc-mode-cards">
									<label class="rc-mode-card <?php echo $widget_mode === 'hub' ? 'active' : ''; ?>">
										<input type="radio" name="rapidchat_widget_mode" value="hub" <?php checked($widget_mode, 'hub'); ?> class="rc-mode-radio">
										<div class="rc-mode-card-content">
											<div class="rc-mode-icon">🌟</div>
											<div class="rc-mode-title"><?php esc_html_e('Self-Service Hub (Portal)', 'rapidchat'); ?></div>
											<div class="rc-mode-desc"><?php esc_html_e('Multi-tab experience with a Home greeting, past conversations and a bottom navigation bar.', 'rapidchat'); ?></div>
										</div>
									</label>

									<label class="rc-mode-card <?php echo $widget_mode === 'direct' ? 'active' : ''; ?>">
										<input type="radio" name="rapidchat_widget_mode" value="direct" <?php checked($widget_mode, 'direct'); ?> class="rc-mode-radio">
										<div class="rc-mode-card-content">
											<div class="rc-mode-icon">⚡</div>
											<div class="rc-mode-title"><?php esc_html_e('Direct Live Chat (Streamlined)', 'rapidchat'); ?></div>
											<div class="rc-mode-desc"><?php esc_html_e('Opens straight into the message form or active chat thread. Hides Home & Help tabs and removes bottom navigation.', 'rapidchat'); ?></div>
										</div>
									</label>
								</div>
							</div>
						</div>

						<div id="rc-hub-tab-toggles" style="<?php echo $widget_mode === 'direct' ? 'display:none;' : ''; ?>">
							<div class="rc-setting-row">
								<div class="rc-setting-row-left">
									<label class="rc-setting-label" for="rc-toggle-home-tab"><?php esc_html_e('Home screen', 'rapidchat'); ?></label>
									<p class="rc-setting-desc"><?php esc_html_e('Hero greeting and the "start a chat" action card.', 'rapidchat'); ?></p>
								</div>
								<div class="rc-setting-row-control">
									<label class="rapidchat-toggle">
										<input type="checkbox" name="rapidchat_show_home_tab" id="rc-toggle-home-tab" value="1" <?php checked($show_home_tab, 1); ?>>
										<span class="rapidchat-toggle-track" aria-hidden="true"></span>
									</label>
								</div>
							</div>
							<?php do_action('rapidchat_settings_hub_toggles'); ?>
						</div>
					</div>

					<div class="rc-card">
						<div class="rc-card-header">
							<h3><span class="dashicons dashicons-format-quote" aria-hidden="true"></span><?php esc_html_e('Header & home screen text', 'rapidchat'); ?></h3>
						</div>
						<div class="rc-setting-row">
							<div class="rc-setting-row-left">
								<label class="rc-setting-label" for="rc-brand-title-input"><?php esc_html_e('Header title', 'rapidchat'); ?></label>
								<p class="rc-setting-desc"><?php esc_html_e('Shown in the widget header and teaser greeting.', 'rapidchat'); ?></p>
							</div>
							<div class="rc-setting-row-control">
								<input type="text" name="rapidchat_brand_title" id="rc-brand-title-input" class="regular-text" value="<?php echo esc_attr($brand_title); ?>">
							</div>
						</div>
						<div class="rc-setting-row">
							<div class="rc-setting-row-left">
								<label class="rc-setting-label" for="rc-greeting-title-input"><?php esc_html_e('Greeting headline', 'rapidchat'); ?></label>
								<p class="rc-setting-desc"><?php esc_html_e('The large line on the home screen.', 'rapidchat'); ?></p>
							</div>
							<div class="rc-setting-row-control">
								<input type="text" name="rapidchat_greeting_title" id="rc-greeting-title-input" class="regular-text" value="<?php echo esc_attr($greeting_title); ?>">
							</div>
						</div>
						<div class="rc-setting-row rc-setting-row--stack">
							<div class="rc-setting-row-left">
								<label class="rc-setting-label" for="rc-greeting-desc-input"><?php esc_html_e('Greeting subtext', 'rapidchat'); ?></label>
							</div>
							<div class="rc-setting-row-control">
								<textarea name="rapidchat_greeting_desc" id="rc-greeting-desc-input" class="large-text" rows="2"><?php echo esc_textarea($greeting_desc); ?></textarea>
							</div>
						</div>
						<div class="rc-setting-row">
							<div class="rc-setting-row-left">
								<label class="rc-setting-label" for="rc-action-title-input"><?php esc_html_e('Action card title', 'rapidchat'); ?></label>
								<p class="rc-setting-desc"><?php esc_html_e('The "start a chat" card on the home screen.', 'rapidchat'); ?></p>
							</div>
							<div class="rc-setting-row-control">
								<input type="text" name="rapidchat_home_action_title" id="rc-action-title-input" class="regular-text" value="<?php echo esc_attr($action_title); ?>" placeholder="<?php esc_attr_e('Send us a message', 'rapidchat'); ?>">
							</div>
						</div>
						<div class="rc-setting-row">
							<div class="rc-setting-row-left">
								<label class="rc-setting-label" for="rc-action-sub-input"><?php esc_html_e('Action card subtitle', 'rapidchat'); ?></label>
							</div>
							<div class="rc-setting-row-control">
								<input type="text" name="rapidchat_home_action_sub" id="rc-action-sub-input" class="regular-text" value="<?php echo esc_attr($action_sub); ?>" placeholder="<?php esc_attr_e('We reply in minutes ⚡', 'rapidchat'); ?>">
							</div>
						</div>
						<div class="rc-setting-row rc-setting-row--stack">
							<div class="rc-setting-row-left">
								<label class="rc-setting-label" for="rc-offline-msg-input"><?php esc_html_e('Offline message', 'rapidchat'); ?></label>
								<p class="rc-setting-desc"><?php esc_html_e('Shown when no agent is online.', 'rapidchat'); ?></p>
							</div>
							<div class="rc-setting-row-control">
								<textarea name="rapidchat_offline_message" id="rc-offline-msg-input" class="large-text" rows="2"><?php echo esc_textarea($offline_msg); ?></textarea>
							</div>
						</div>
					</div>

					<div class="rc-card">
						<div class="rc-card-header">
							<h3><span class="dashicons dashicons-feedback" aria-hidden="true"></span><?php esc_html_e('Pre-chat form', 'rapidchat'); ?></h3>
							<span class="rc-badge rc-badge-muted"><?php esc_html_e('Name & email always required', 'rapidchat'); ?></span>
						</div>
						<div class="rc-setting-row">
							<div class="rc-setting-row-left">
								<label class="rc-setting-label" for="rc-prechat-title-input"><?php esc_html_e('Form heading', 'rapidchat'); ?></label>
							</div>
							<div class="rc-setting-row-control">
								<input type="text" name="rapidchat_prechat_intro_title" id="rc-prechat-title-input" class="regular-text" value="<?php echo esc_attr($prechat_intro_title); ?>" placeholder="<?php esc_attr_e('Start a conversation', 'rapidchat'); ?>">
							</div>
						</div>
						<div class="rc-setting-row rc-setting-row--stack">
							<div class="rc-setting-row-left">
								<label class="rc-setting-label" for="rc-prechat-text-input"><?php esc_html_e('Form subtext', 'rapidchat'); ?></label>
							</div>
							<div class="rc-setting-row-control">
								<textarea name="rapidchat_prechat_intro_text" id="rc-prechat-text-input" class="large-text" rows="2"><?php echo esc_textarea($prechat_intro_text); ?></textarea>
							</div>
						</div>
						<?php do_action('rapidchat_settings_prechat_toggles'); ?>
						<div class="rc-setting-row">
							<div class="rc-setting-row-left">
								<span class="rc-setting-label"><?php esc_html_e('Message field', 'rapidchat'); ?></span>
								<p class="rc-setting-desc"><?php esc_html_e('Ask "How can we help you?" before the chat starts.', 'rapidchat'); ?></p>
							</div>
							<div class="rc-setting-row-control">
								<label class="rapidchat-toggle">
									<input type="checkbox" name="rapidchat_prechat_show_message" value="1" <?php checked($prechat_show_message, 1); ?> aria-label="<?php esc_attr_e('Message field', 'rapidchat'); ?>">
									<span class="rapidchat-toggle-track" aria-hidden="true"></span>
								</label>
							</div>
						</div>
						<div class="rc-setting-row">
							<div class="rc-setting-row-left">
								<span class="rc-setting-label"><?php esc_html_e('Require the message', 'rapidchat'); ?></span>
								<p class="rc-setting-desc"><?php esc_html_e('Visitors must describe their question to start.', 'rapidchat'); ?></p>
							</div>
							<div class="rc-setting-row-control">
								<label class="rapidchat-toggle">
									<input type="checkbox" name="rapidchat_prechat_require_message" value="1" <?php checked($prechat_require_message, 1); ?> aria-label="<?php esc_attr_e('Require the message', 'rapidchat'); ?>">
									<span class="rapidchat-toggle-track" aria-hidden="true"></span>
								</label>
							</div>
						</div>
					</div>

					<div class="rc-card">
						<div class="rc-card-header">
							<h3><span class="dashicons dashicons-art" aria-hidden="true"></span><?php esc_html_e('Color & placement', 'rapidchat'); ?></h3>
						</div>
						<div class="rc-setting-row rc-setting-row--stack">
							<div class="rc-setting-row-left">
								<span class="rc-setting-label"><?php esc_html_e('Brand color', 'rapidchat'); ?></span>
								<p class="rc-setting-desc"><?php esc_html_e('Drives the header gradient, launcher, send button and accents.', 'rapidchat'); ?></p>
							</div>
							<div class="rc-setting-row-control">
								<div class="rc-color-picker-row">
									<input type="color" id="rc-primary-color-picker" name="rapidchat_primary_color" value="<?php echo esc_attr($primary_color); ?>" class="rc-color-input" aria-label="<?php esc_attr_e('Brand color', 'rapidchat'); ?>">
									<input type="text" id="rc-primary-color-hex" value="<?php echo esc_attr($primary_color); ?>" class="rc-hex-input" maxlength="7" placeholder="#1e3c72" aria-label="<?php esc_attr_e('Brand color hex value', 'rapidchat'); ?>">
								</div>
								<div class="rc-color-presets">
									<span class="rc-preset-label"><?php esc_html_e('Presets:', 'rapidchat'); ?></span>
									<div class="rc-preset-chips">
										<?php foreach ($presets as $p) : ?>
											<button type="button" class="rc-preset-chip <?php echo (strtolower($primary_color) === strtolower($p['color'])) ? 'active' : ''; ?>"
												data-color="<?php echo esc_attr($p['color']); ?>"
												title="<?php echo esc_attr($p['name']); ?>">
												<span class="rc-preset-dot" style="background-color: <?php echo esc_attr($p['color']); ?>;"></span>
												<?php echo esc_html($p['name']); ?>
											</button>
										<?php endforeach; ?>
									</div>
								</div>
							</div>
						</div>
						<div class="rc-setting-row">
							<div class="rc-setting-row-left">
								<label class="rc-setting-label" for="rc-widget-pos-select"><?php esc_html_e('Screen position', 'rapidchat'); ?></label>
								<p class="rc-setting-desc"><?php esc_html_e('Which corner the widget floats in.', 'rapidchat'); ?></p>
							</div>
							<div class="rc-setting-row-control">
								<select name="rapidchat_widget_position" id="rc-widget-pos-select">
									<option value="right" <?php selected($widget_pos, 'right'); ?>><?php esc_html_e('Bottom right', 'rapidchat'); ?></option>
									<option value="left" <?php selected($widget_pos, 'left'); ?>><?php esc_html_e('Bottom left', 'rapidchat'); ?></option>
								</select>
							</div>
						</div>
						<div class="rc-setting-row">
							<div class="rc-setting-row-left">
								<span class="rc-setting-label"><?php esc_html_e('Show on every page', 'rapidchat'); ?></span>
								<p class="rc-setting-desc"><?php esc_html_e('When off, the widget only appears where the [rapid_chat] shortcode is placed.', 'rapidchat'); ?></p>
							</div>
							<div class="rc-setting-row-control">
								<label class="rapidchat-toggle">
									<input type="checkbox" name="rapidchat_auto_display" value="1" <?php checked($auto_display, 1); ?> aria-label="<?php esc_attr_e('Show on every page', 'rapidchat'); ?>">
									<span class="rapidchat-toggle-track" aria-hidden="true"></span>
								</label>
							</div>
						</div>
					</div>
				</form>
			</div>

			<div class="rc-customizer-preview-col">
				<div class="rc-preview-sticky-wrap">
					<div class="rc-preview-header">
						<h4>👁️ <?php esc_html_e('Live Widget Preview', 'rapidchat'); ?></h4>
						<div class="rc-preview-header-tags">
							<span class="rc-preview-badge-mode" id="rc-preview-mode-tag"><?php echo $widget_mode === 'direct' ? esc_html__('Direct Live Chat', 'rapidchat') : esc_html__('Self-Service Hub', 'rapidchat'); ?></span>
							<span class="rc-preview-badge"><?php esc_html_e('Real-time', 'rapidchat'); ?></span>
						</div>
					</div>

					<div class="rc-preview-container" id="rc-preview-container">
						<div class="rc-mockup-widget" id="rc-mockup-widget">
							<div class="rc-mockup-header" id="rc-mockup-header">
								<div class="rc-mockup-team">
									<div class="rc-mockup-avatar">👨‍💻</div>
									<div class="rc-mockup-info">
										<div class="rc-mockup-title" id="rc-mockup-title"><?php echo esc_html($brand_title); ?></div>
										<div class="rc-mockup-sub"><?php esc_html_e('Online • Typically replies in minutes', 'rapidchat'); ?></div>
									</div>
								</div>
								<div class="rc-mockup-close">&times;</div>
							</div>

							<div class="rc-mockup-body">
								<div id="rc-mockup-home-view" style="<?php echo $widget_mode === 'direct' ? 'display:none;' : ''; ?>">
									<div class="rc-mockup-welcome" id="rc-mockup-welcome-box">
										<h4 id="rc-mockup-welcome-title"><?php echo esc_html($greeting_title); ?></h4>
										<p id="rc-mockup-welcome-desc"><?php echo esc_html($greeting_desc); ?></p>
									</div>

									<div class="rc-mockup-action-card" id="rc-mockup-action-card">
										<div class="rc-mockup-card-icon">💬</div>
										<div class="rc-mockup-card-text">
											<div class="rc-mockup-card-title" id="rc-mockup-card-title"><?php echo esc_html($action_title); ?></div>
											<div class="rc-mockup-card-sub" id="rc-mockup-card-sub"><?php echo esc_html($action_sub); ?></div>
										</div>
										<div class="rc-mockup-card-arrow">&rsaquo;</div>
									</div>

									<div class="rc-mockup-faqs-preview" id="rc-mockup-faqs-preview" style="<?php echo $show_home_faqs ? '' : 'display:none;'; ?>">
										<div class="rc-mockup-faq-title"><?php esc_html_e('Frequently Asked Questions', 'rapidchat'); ?></div>
										<div class="rc-mockup-faq-item">
											<span><?php esc_html_e('What are your business hours?', 'rapidchat'); ?></span>
											<span>+</span>
										</div>
										<div class="rc-mockup-faq-item">
											<span><?php esc_html_e('How fast do you reply?', 'rapidchat'); ?></span>
											<span>+</span>
										</div>
									</div>
								</div>

								<div id="rc-mockup-direct-view" style="<?php echo $widget_mode === 'direct' ? '' : 'display:none;'; ?>">
									<div class="rc-mockup-messages">
										<div class="rc-mockup-msg rc-msg-operator">
											<span>👋 <?php esc_html_e('Hello! How can we assist you today?', 'rapidchat'); ?></span>
										</div>
										<div class="rc-mockup-msg rc-msg-visitor">
											<span><?php esc_html_e('I have a question about pricing.', 'rapidchat'); ?></span>
										</div>
									</div>
								</div>
							</div>

							<div class="rc-mockup-composer" id="rc-mockup-composer" style="<?php echo $widget_mode === 'direct' ? '' : 'display:none;'; ?>">
								<input type="text" placeholder="<?php esc_attr_e('Write a message...', 'rapidchat'); ?>" readonly>
								<button type="button" class="rc-mockup-send-btn" id="rc-mockup-send-btn">
									<?php rapidchat_icon_e('send', 15); ?>
								</button>
							</div>

							<div class="rc-mockup-nav" id="rc-mockup-nav" style="<?php echo $widget_mode === 'direct' ? 'display:none;' : ''; ?>">
								<div class="rc-mockup-nav-item active" id="rc-mockup-nav-home">
									<span>🏠</span>
									<span><?php esc_html_e('Home', 'rapidchat'); ?></span>
								</div>
								<div class="rc-mockup-nav-item" id="rc-mockup-nav-messages">
									<span>💬</span>
									<span><?php esc_html_e('Messages', 'rapidchat'); ?></span>
								</div>
								<div class="rc-mockup-nav-item" id="rc-mockup-nav-help" style="<?php echo $show_help_tab ? '' : 'display:none;'; ?>">
									<span>❓</span>
									<span><?php esc_html_e('Help', 'rapidchat'); ?></span>
								</div>
							</div>
						</div>

						<div class="rc-mockup-launcher-wrap" id="rc-mockup-launcher-wrap">
							<div class="rc-mockup-launcher" id="rc-mockup-launcher">
								<?php rapidchat_icon_e('comment-dots', 24); ?>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<?php rapidchat_enqueue_settings_preview(); ?>

	<?php
}

/** Agents tab: everyone who can answer chats. */
function rapidchat_settings_tab_agents_free() {
	$agents = get_users(array('capability__in' => array(RAPIDCHAT_CAP_OPERATE, RAPIDCHAT_CAP_MANAGE), 'orderby' => 'display_name'));
	$role_names = wp_roles()->get_names();
	?>
				<div class="rc-card" style="margin-bottom: 24px;">
					<div class="rc-card-header">
						<h3><span class="dashicons dashicons-admin-users" aria-hidden="true"></span> <?php esc_html_e('Agents', 'rapidchat'); ?></h3>
					</div>
					<p class="rc-card-intro">
						<?php esc_html_e('Administrators, and users whose role has the rapidchat_operate capability, can answer chats in the Live Inbox.', 'rapidchat'); ?>
					</p>
					<div class="rc-table-scroll">
						<table class="rapidchat-table">
							<thead>
								<tr>
									<th><?php esc_html_e('Operator Name', 'rapidchat'); ?></th>
									<th><?php esc_html_e('Email Address', 'rapidchat'); ?></th>
									<th><?php esc_html_e('WordPress Role', 'rapidchat'); ?></th>
									<th><?php esc_html_e('Chat Access', 'rapidchat'); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($agents as $agent) : ?>
									<?php $roles = array_map(function ($slug) use ($role_names) { return isset($role_names[$slug]) ? translate_user_role($role_names[$slug]) : $slug; }, (array) $agent->roles); ?>
								<tr>
									<td><strong><?php echo esc_html($agent->display_name); ?></strong></td>
									<td><?php echo esc_html($agent->user_email); ?></td>
									<td><span class="rapidchat-status-pill is-ok"><?php echo esc_html(implode(', ', $roles)); ?></span></td>
									<td><span class="rapidchat-status-pill is-ok"><?php echo user_can($agent, RAPIDCHAT_CAP_MANAGE) ? esc_html__('Full access', 'rapidchat') : esc_html__('View & Reply', 'rapidchat'); ?></span></td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
	<?php
}

function rapidchat_settings_tab_canned() {
	global $wpdb;
	$canned_items = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY shortcut ASC', $wpdb->rapidchat_canned_responses));
	?>
			<div class="rc-card">
				<div class="rc-card-header">
					<h3>
						<span class="dashicons dashicons-plus-alt" aria-hidden="true"></span>
						<?php esc_html_e('Add New Canned Response / Quick Reply', 'rapidchat'); ?>
					</h3>
				</div>
				<form method="post">
					<?php wp_nonce_field('rapidchat_canned', 'rapidchat_canned_nonce'); ?>
					<div class="rc-setting-rows">
						<div class="rc-setting-row">
							<div class="rc-setting-row-left">
								<span class="rc-setting-label"><?php esc_html_e('Shortcut', 'rapidchat'); ?></span>
								<p class="rc-setting-desc"><?php esc_html_e('Operators trigger this in the live console by typing /shortcut', 'rapidchat'); ?></p>
							</div>
							<div class="rc-setting-row-control">
								/ <input type="text" name="shortcut" placeholder="<?php esc_attr_e('e.g. pricing, docs, hello', 'rapidchat'); ?>" required pattern="[a-zA-Z0-9_-]+" style="width: 200px;">
							</div>
						</div>
						<div class="rc-setting-row">
							<div class="rc-setting-row-left">
								<span class="rc-setting-label"><?php esc_html_e('Title / Description', 'rapidchat'); ?></span>
							</div>
							<div class="rc-setting-row-control">
								<input type="text" name="title" placeholder="<?php esc_attr_e('e.g. Pricing & Plans Overview', 'rapidchat'); ?>" required class="regular-text">
							</div>
						</div>
						<div class="rc-setting-row rc-setting-row--stack">
							<div class="rc-setting-row-left">
								<span class="rc-setting-label"><?php esc_html_e('Response Template', 'rapidchat'); ?></span>
							</div>
							<div class="rc-setting-row-control">
								<textarea name="content" rows="3" class="large-text" placeholder="<?php esc_attr_e('e.g. You can find our complete pricing details at https://example.com/pricing', 'rapidchat'); ?>" required></textarea>
							</div>
						</div>
					</div>
					<div class="rc-card-footer">
						<?php submit_button(__('Add Canned Reply', 'rapidchat'), 'primary', 'submit_canned'); ?>
					</div>

				</form>
			</div>

			<div class="rc-card">
				<div class="rc-card-header"><h3><span class="dashicons dashicons-list-view" aria-hidden="true"></span><?php esc_html_e('Existing Canned Responses', 'rapidchat'); ?></h3></div>
				<div class="rc-table-scroll">
				<table class="rapidchat-table">
					<thead>
						<tr>
							<th><?php esc_html_e('Command', 'rapidchat'); ?></th>
							<th><?php esc_html_e('Title', 'rapidchat'); ?></th>
							<th><?php esc_html_e('Response Template', 'rapidchat'); ?></th>
							<th><?php esc_html_e('Actions', 'rapidchat'); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php if (!$canned_items) : ?>
						<tr><td colspan="4" class="rc-empty"><?php esc_html_e('No canned responses added yet.', 'rapidchat'); ?></td></tr>
					<?php else : ?>
						<?php foreach ($canned_items as $item) : ?>
							<tr>
								<td><code>/<?php echo esc_html($item->shortcut); ?></code></td>
								<td><strong><?php echo esc_html($item->title); ?></strong></td>
								<td><?php echo nl2br(esc_html($item->content)); ?></td>
								<td>
									<a href="<?php echo esc_url(wp_nonce_url(add_query_arg(array('tab' => 'canned', 'delete_canned' => $item->id)), 'rapidchat_delete_canned')); ?>"
									   class="button button-small button-link-delete rc-confirm"
									   data-confirm="<?php esc_attr_e('Delete this canned response?', 'rapidchat'); ?>">
										<?php esc_html_e('Delete', 'rapidchat'); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
				</div>
			</div>

	<?php
}

function rapidchat_settings_tab_general() {
	?>
			<form method="post" id="rc-general-form">
				<?php wp_nonce_field('rapidchat_settings', 'rapidchat_settings_nonce'); ?>
				<input type="hidden" name="rapidchat_tab" value="general">

				<?php if (!rapidchat_is_pro()) : ?>
				<div class="rc-card">
					<div class="rc-card-header">
						<h3><span class="dashicons dashicons-admin-users" aria-hidden="true"></span><?php esc_html_e('Who can answer chats', 'rapidchat'); ?></h3>
						<span class="rc-badge"><?php esc_html_e('Access', 'rapidchat'); ?></span>
					</div>
					<p class="rc-card-intro">
						<?php esc_html_e('Administrators can answer chats, as can any user whose role has the rapidchat_operate capability (grant it with a role editor plugin).', 'rapidchat'); ?>
					</p>
					<p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=rapidchat-settings&tab=agents')); ?>"><?php esc_html_e('View agents', 'rapidchat'); ?></a></p>
				</div>
				<?php endif; ?>
				<?php do_action('rapidchat_settings_general_cards'); ?>

				<div class="rc-card">
					<div class="rc-card-header">
						<h3><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php esc_html_e('Privacy & network', 'rapidchat'); ?></h3>
					</div>
					<div class="rc-setting-row">
						<div class="rc-setting-row-left">
							<span class="rc-setting-label"><?php esc_html_e('Visitor geolocation', 'rapidchat'); ?></span>
							<p class="rc-setting-desc"><?php esc_html_e('Off by default. When on, each visitor\'s IP address is sent to api.ipbase.com to look up their country for the flag shown in the inbox. Runs in the background, never slowing down the visitor.', 'rapidchat'); ?></p>
						</div>
						<div class="rc-setting-row-control">
							<label class="rapidchat-toggle">
								<input type="checkbox" name="rapidchat_geolocate" value="1" aria-label="<?php esc_attr_e('Visitor geolocation', 'rapidchat'); ?>"
									<?php checked(get_option('rapidchat_geolocate', 0)); ?>>
								<span class="rapidchat-toggle-track" aria-hidden="true"></span>
							</label>
						</div>
					</div>
					<div class="rc-setting-row">
						<div class="rc-setting-row-left">
							<span class="rc-setting-label"><?php esc_html_e('Behind a reverse proxy / CDN', 'rapidchat'); ?></span>
							<p class="rc-setting-desc"><?php esc_html_e('Trust the X-Forwarded-For header for visitor IPs. Only enable behind Cloudflare, Nginx, or a proxy you control.', 'rapidchat'); ?></p>
						</div>
						<div class="rc-setting-row-control">
							<label class="rapidchat-toggle">
								<input type="checkbox" name="rapidchat_trust_proxy" value="1" aria-label="<?php esc_attr_e('Behind a reverse proxy / CDN', 'rapidchat'); ?>"
									<?php checked(get_option('rapidchat_trust_proxy')); ?>>
								<span class="rapidchat-toggle-track" aria-hidden="true"></span>
							</label>
						</div>
					</div>
				</div>
			</form>
	<?php
}

/** May the current user open and save settings tab $tab? */
function rapidchat_settings_can($tab) {
	$caps = rapidchat_settings_tab_caps();

	return isset($caps[$tab]) && current_user_can($caps[$tab]);
}

function rapidchat_handle_canned_save() {
	if (!rapidchat_settings_can('canned')) {
		return;
	}

	global $wpdb;

	if (!$wpdb->rapidchat_canned_responses || $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->rapidchat_canned_responses))) !== $wpdb->rapidchat_canned_responses) {
		return;
	}

	if (isset($_GET['delete_canned'])) {
		check_admin_referer('rapidchat_delete_canned');
		$id = absint(wp_unslash($_GET['delete_canned']));
		$wpdb->delete($wpdb->rapidchat_canned_responses, array('id' => $id), array('%d'));
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Canned response deleted.', 'rapidchat') . '</p></div>';
		return;
	}

	if (!isset($_POST['rapidchat_canned_nonce'])) {
		return;
	}

	check_admin_referer('rapidchat_canned', 'rapidchat_canned_nonce');

	$shortcut = isset($_POST['shortcut']) ? sanitize_key(wp_unslash($_POST['shortcut'])) : '';
	$title    = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
	$content  = isset($_POST['content']) ? sanitize_textarea_field(wp_unslash($_POST['content'])) : '';

	if ($shortcut === '' || $content === '') {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Shortcut and template content are required.', 'rapidchat') . '</p></div>';
		return;
	}

	$wpdb->insert(
		$wpdb->rapidchat_canned_responses,
		array(
			'shortcut'   => $shortcut,
			'title'      => $title,
			'content'    => $content,
			'created_at' => current_time('mysql'),
		),
		array('%s', '%s', '%s', '%s')
	);

	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Canned response added!', 'rapidchat') . '</p></div>';
}

function rapidchat_handle_settings_save() {
	if (!isset($_POST['rapidchat_settings_nonce'])) {
		return;
	}

	check_admin_referer('rapidchat_settings', 'rapidchat_settings_nonce');

	if (!rapidchat_settings_can('general')) {
		wp_die(esc_html__('You do not have permission to do that.', 'rapidchat'));
	}

	$tab = isset($_POST['rapidchat_tab']) ? sanitize_key(wp_unslash($_POST['rapidchat_tab'])) : 'general';

	if ($tab === 'branding') {
		if (isset($_POST['rapidchat_widget_mode'])) {
			$mode = sanitize_key(wp_unslash($_POST['rapidchat_widget_mode']));
			if ($mode === 'direct' || $mode === 'hub') {
				update_option('rapidchat_widget_mode', $mode);
			}
		}
		update_option('rapidchat_show_home_tab', isset($_POST['rapidchat_show_home_tab']) ? 1 : 0);

		if (isset($_POST['rapidchat_brand_title'])) {
			update_option('rapidchat_brand_title', rapidchat_encode_emoji_option(sanitize_text_field(wp_unslash($_POST['rapidchat_brand_title']))));
		}
		if (isset($_POST['rapidchat_greeting_title'])) {
			update_option('rapidchat_greeting_title', rapidchat_encode_emoji_option(sanitize_text_field(wp_unslash($_POST['rapidchat_greeting_title']))));
		}
		if (isset($_POST['rapidchat_greeting_desc'])) {
			update_option('rapidchat_greeting_desc', rapidchat_encode_emoji_option(sanitize_textarea_field(wp_unslash($_POST['rapidchat_greeting_desc']))));
		}
		if (isset($_POST['rapidchat_home_action_title'])) {
			update_option('rapidchat_home_action_title', rapidchat_encode_emoji_option(sanitize_text_field(wp_unslash($_POST['rapidchat_home_action_title']))));
		}
		if (isset($_POST['rapidchat_home_action_sub'])) {
			update_option('rapidchat_home_action_sub', rapidchat_encode_emoji_option(sanitize_text_field(wp_unslash($_POST['rapidchat_home_action_sub']))));
		}
		if (isset($_POST['rapidchat_offline_message'])) {
			update_option('rapidchat_offline_message', rapidchat_encode_emoji_option(sanitize_textarea_field(wp_unslash($_POST['rapidchat_offline_message']))));
		}
		if (isset($_POST['rapidchat_primary_color'])) {
			$color = sanitize_hex_color(wp_unslash($_POST['rapidchat_primary_color']));
			if ($color) {
				update_option('rapidchat_primary_color', $color);
			}
		}
		if (isset($_POST['rapidchat_widget_position'])) {
			$pos = sanitize_key(wp_unslash($_POST['rapidchat_widget_position']));
			if ($pos === 'left' || $pos === 'right') {
				update_option('rapidchat_widget_position', $pos);
			}
		}
		update_option('rapidchat_auto_display', isset($_POST['rapidchat_auto_display']) ? 1 : 0);

		update_option('rapidchat_prechat_show_message', isset($_POST['rapidchat_prechat_show_message']) ? 1 : 0);
		update_option('rapidchat_prechat_require_message', isset($_POST['rapidchat_prechat_require_message']) ? 1 : 0);
		if (isset($_POST['rapidchat_prechat_intro_title'])) {
			update_option('rapidchat_prechat_intro_title', rapidchat_encode_emoji_option(sanitize_text_field(wp_unslash($_POST['rapidchat_prechat_intro_title']))));
		}
		if (isset($_POST['rapidchat_prechat_intro_text'])) {
			update_option('rapidchat_prechat_intro_text', rapidchat_encode_emoji_option(sanitize_textarea_field(wp_unslash($_POST['rapidchat_prechat_intro_text']))));
		}
	} else {
		update_option('rapidchat_geolocate', isset($_POST['rapidchat_geolocate']) ? 1 : 0);
		update_option('rapidchat_trust_proxy', isset($_POST['rapidchat_trust_proxy']) ? 1 : 0);
	}

	do_action('rapidchat_settings_save_' . $tab);

	if ($tab === 'general') {
		rapidchat_register_capabilities();
		do_action('rapidchat_settings_saved', (array) get_option('rapidchat_operator_roles', array('administrator')));
	}

	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved successfully.', 'rapidchat') . '</p></div>';
}

/** The Widget Customizer's live-preview script, enqueued with its two translated labels. */
function rapidchat_enqueue_settings_preview() {
	wp_enqueue_script('rapidchat-settings-preview', RAPIDCHAT_JS . '/settings-preview.js', array('jquery'), rapidchat_asset_version('js/settings-preview.js'), true);
	wp_localize_script('rapidchat-settings-preview', 'rapidchatPreview', array(
		'direct'    => __('Direct chat', 'rapidchat'),
		'hub'       => __('Hub', 'rapidchat'),
		'directTag' => __('Direct Live Chat', 'rapidchat'),
		'hubTag'    => __('Self-Service Hub', 'rapidchat'),
	));
}
