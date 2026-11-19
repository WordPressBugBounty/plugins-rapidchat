<?php
/** Front-end chat widget markup. */

defined('ABSPATH') || exit;

function rapidchat_widget_html() {
	$brand_title    = rapidchat_decode_emoji_option(get_option('rapidchat_brand_title', get_bloginfo('name') . ' ' . __('Support', 'rapidchat')));
	$greeting_title = rapidchat_decode_emoji_option(get_option('rapidchat_greeting_title', __('Hi there 👋', 'rapidchat')));
	$greeting_desc  = rapidchat_decode_emoji_option(get_option('rapidchat_greeting_desc', __('How can we help you today? Our team typically replies in minutes.', 'rapidchat')));
	$widget_pos     = get_option('rapidchat_widget_position', 'right');

	$widget_mode    = get_option('rapidchat_widget_mode', 'hub');
	$show_home_tab  = (int) get_option('rapidchat_show_home_tab', 1);
	$show_help_tab  = rapidchat_widget_flag('show_help');
	$show_home_faqs = rapidchat_widget_flag('show_faqs');

	$is_online      = (bool) apply_filters('rapidchat_widget_is_online', rapidchat_operators_online_count() > 0);
	$action_title   = rapidchat_decode_emoji_option(get_option('rapidchat_home_action_title', __('Send us a message', 'rapidchat')));
	$action_sub     = rapidchat_decode_emoji_option(get_option('rapidchat_home_action_sub', $is_online ? __('We reply in minutes ⚡', 'rapidchat') : __('Leave a message for our team ✉️', 'rapidchat')));
	$offline_msg    = rapidchat_decode_emoji_option(get_option('rapidchat_offline_message', __('Leave your message and email below, and our team will get back to you shortly!', 'rapidchat')));

	$presence_text  = $is_online ? __('Online • Typically replies in minutes', 'rapidchat') : __('Away • Leave a message', 'rapidchat');

	$prechat_show_message    = (int) get_option('rapidchat_prechat_show_message', 1);
	$prechat_require_message = (int) get_option('rapidchat_prechat_require_message', 0);
	$prechat_intro_title     = rapidchat_decode_emoji_option(get_option('rapidchat_prechat_intro_title', __('Start a conversation', 'rapidchat')));
	$prechat_intro_text      = rapidchat_decode_emoji_option(get_option('rapidchat_prechat_intro_text', __('Tell us who you are so an agent can pick up right where you left off.', 'rapidchat')));
	?>
<?php do_action('rapidchat_before_widget_render'); ?>
<div class="live-support-chat rc-position-<?php echo esc_attr($widget_pos); ?> rc-mode-<?php echo esc_attr($widget_mode); ?>" id="rapidchat-widget"
	 data-widget-mode="<?php echo esc_attr($widget_mode); ?>"
	 data-show-home="<?php echo esc_attr($show_home_tab); ?>"
	 data-show-help="<?php echo esc_attr($show_help_tab); ?>"
	 data-show-faqs="<?php echo esc_attr($show_home_faqs); ?>">

	<?php do_action('rapidchat_widget_top', $brand_title); ?>

	<a href="#" class="open-chat-widget" aria-label="<?php esc_attr_e('Open chat widget', 'rapidchat'); ?>">
		<span class="rc-launcher-open-icon"><?php rapidchat_icon_e('comment-dots', 24); ?></span>
		<span class="rc-launcher-close-icon" style="display: none;"><?php rapidchat_icon_e('close', 20); ?></span>
		<span class="rc-unread-badge" style="display: none;">0</span>
	</a>

	<div class="chat-widget" style="display: none;">

		<div class="chat-widget-header">
			<button class="rc-header-back-btn" aria-label="<?php esc_attr_e('Back', 'rapidchat'); ?>" style="display: none;">&lsaquo;</button>
			<div class="rc-header-team-info">
				<div class="rc-avatar-stack">
					<div class="rc-team-avatar">👨‍💻</div>
					<div class="rc-team-avatar">👩‍💼</div>
					<span class="rc-status-dot <?php echo $is_online ? 'online' : 'offline'; ?>"></span>
				</div>
				<div class="rc-header-text">
					<div class="rc-header-title"><?php echo esc_html($brand_title); ?></div>
					<div class="rc-header-subtitle">
						<span class="rc-presence-text" id="rapidchat-presence-text"><?php echo esc_html($presence_text); ?></span>
					</div>
				</div>
			</div>

			<div class="rc-header-actions">
				<button class="rc-header-icon-btn rc-sound-toggle-btn" title="<?php esc_attr_e('Toggle Sound', 'rapidchat'); ?>" aria-label="<?php esc_attr_e('Toggle Sound', 'rapidchat'); ?>">
					<?php rapidchat_icon_e('volume-up', 17); ?>
				</button>
				<div class="chat-menu-container">
					<button class="chat-menu-btn rc-header-icon-btn" aria-label="<?php esc_attr_e('Options', 'rapidchat'); ?>" aria-expanded="false">
						<?php rapidchat_icon_e('bars', 17); ?>
					</button>
					<ul class="chat-menu" hidden>
						<li><a href="#" class="chat-widget-new-conversation"><?php esc_html_e('➕ Start New Chat', 'rapidchat'); ?></a></li>
						<li><a href="#" class="rc-send-transcript-btn"><?php esc_html_e('📧 Email Transcript', 'rapidchat'); ?></a></li>
						<li><a href="#" class="close-chat-session-btn"><?php esc_html_e('End Chat Session', 'rapidchat'); ?></a></li>
					</ul>
				</div>
				<a href="#" class="close-chat-widget-btn rc-header-icon-btn" aria-label="<?php esc_attr_e('Minimize chat', 'rapidchat'); ?>">&times;</a>
			</div>
		</div>

		<div class="chat-confirm-box" style="display: none;">
			<div class="chat-confirm-inner">
				<h4><?php esc_html_e('End Chat Session?', 'rapidchat'); ?></h4>
				<p><?php esc_html_e('Are you sure you want to end this live conversation? You will be able to review your transcript.', 'rapidchat'); ?></p>
				<div class="chat-confirm-actions">
					<button class="confirm-close-chat-btn"><?php esc_html_e('Yes, End Chat', 'rapidchat'); ?></button>
					<button class="cancel-close-chat-btn"><?php esc_html_e('Cancel', 'rapidchat'); ?></button>
				</div>
			</div>
		</div>

		<div class="chat-widget-content">

			<?php if ($show_home_tab && $widget_mode === 'hub') : ?>
			<div class="rc-tab-view rc-tab-home" style="display: flex;">
				<div class="rc-home-hero">
					<h3><?php echo esc_html($greeting_title); ?></h3>
					<p><?php echo esc_html($greeting_desc); ?></p>
				</div>

				<?php if (!$is_online) : ?>
				<div class="rc-offline-banner">
					<span class="rc-offline-icon">🌙</span>
					<div class="rc-offline-text">
						<strong><?php esc_html_e('Team is currently offline', 'rapidchat'); ?></strong>
						<p><?php echo esc_html($offline_msg); ?></p>
					</div>
				</div>
				<?php endif; ?>

				<div class="rc-home-actions">
					<button type="button" class="rc-start-chat-card-btn">
						<div class="rc-card-icon">💬</div>
						<div class="rc-card-text">
							<span class="rc-card-title"><?php echo esc_html($action_title); ?></span>
							<span class="rc-card-sub"><?php echo esc_html($action_sub); ?></span>
						</div>
						<span class="rc-card-arrow">&rsaquo;</span>
					</button>
				</div>

				<?php do_action('rapidchat_widget_home_after'); ?>
			</div>
			<?php endif; ?>

			<div class="rc-tab-view rc-tab-login" style="display: none;">
				<div class="welcome-message">
					<h2><?php echo esc_html($prechat_intro_title); ?></h2>
					<p><?php echo esc_html($prechat_intro_text); ?></p>
				</div>

				<form id="chat-login-form" method="post" autocomplete="on">
					<input type="hidden" name="action" value="rapidchat_authenticate_user">

					<div class="rc-form-group">
						<label class="rc-form-label" for="rc-login-name"><?php esc_html_e('Your name', 'rapidchat'); ?> <span class="rc-required">*</span></label>
						<input type="text" id="rc-login-name" name="name" placeholder="<?php esc_attr_e('Jane Doe', 'rapidchat'); ?>" maxlength="100" required>
					</div>

					<div class="rc-form-group">
						<label class="rc-form-label" for="rc-login-email"><?php esc_html_e('Email address', 'rapidchat'); ?> <span class="rc-required">*</span></label>
						<input type="email" id="rc-login-email" name="email" placeholder="<?php esc_attr_e('jane@example.com', 'rapidchat'); ?>" maxlength="191" required>
						<span class="rc-field-hint"><?php esc_html_e('So we can reach you if the chat drops.', 'rapidchat'); ?></span>
					</div>

					<?php do_action('rapidchat_prechat_fields'); ?>

					<?php if ($prechat_show_message) : ?>
					<div class="rc-form-group">
						<label class="rc-form-label" for="rc-login-msg">
							<?php esc_html_e('How can we help you?', 'rapidchat'); ?>
							<?php if ($prechat_require_message) : ?><span class="rc-required">*</span><?php endif; ?>
						</label>
						<textarea id="rc-login-msg" name="initial_message" class="rc-initial-msg-input" placeholder="<?php esc_attr_e('Type your question or message here…', 'rapidchat'); ?>" rows="3" maxlength="1000" <?php echo $prechat_require_message ? 'required' : ''; ?>></textarea>
					</div>
					<?php endif; ?>

					<div class="msg" role="status"></div>
					<button type="submit" class="rc-btn-primary"><?php echo $is_online ? esc_html__('Start Live Chat', 'rapidchat') : esc_html__('Send Message', 'rapidchat'); ?></button>
				</form>
			</div>

			<div class="rc-tab-view rc-tab-conversations" style="display: none;">
				<div class="chat-widget-conversations-tab"></div>
			</div>

			<div class="rc-tab-view rc-tab-thread" style="display: none;">
				<div class="chat-widget-conversation-tab"></div>

				<div class="rc-rating-card" style="display: none;">
					<button type="button" class="rc-rating-close" aria-label="<?php esc_attr_e('Close', 'rapidchat'); ?>" title="<?php esc_attr_e('Close', 'rapidchat'); ?>">&times;</button>
					<h4><?php esc_html_e('How was our support?', 'rapidchat'); ?></h4>
					<p><?php esc_html_e('Your feedback helps us continuously improve.', 'rapidchat'); ?></p>
					<div class="rc-star-rating">
						<span class="rc-star" data-rating="1" title="1 star">★</span>
						<span class="rc-star" data-rating="2" title="2 stars">★</span>
						<span class="rc-star" data-rating="3" title="3 stars">★</span>
						<span class="rc-star" data-rating="4" title="4 stars">★</span>
						<span class="rc-star" data-rating="5" title="5 stars">★</span>
					</div>
					<textarea class="rc-feedback-input" placeholder="<?php esc_attr_e('Any additional comments? (Optional)', 'rapidchat'); ?>" maxlength="500"></textarea>
					<button type="button" class="rc-submit-rating-btn"><?php esc_html_e('Submit Feedback', 'rapidchat'); ?></button>
					<div class="rc-rating-thankyou" style="display: none;">
						<p><?php esc_html_e('🎉 Thank you for your feedback!', 'rapidchat'); ?></p>
						<button type="button" class="rc-start-new-chat-btn rc-btn-primary" style="margin-top: 10px; width: 100%;"><?php esc_html_e('Start New Conversation', 'rapidchat'); ?></button>
					</div>
				</div>
			</div>

			<?php do_action('rapidchat_widget_views', $widget_mode); ?>

		</div>

		<?php
		$has_bottom_nav = ($widget_mode === 'hub') && ($show_home_tab || $show_help_tab);
		if ($has_bottom_nav) :
		?>
		<div class="rc-bottom-nav">
			<?php if ($show_home_tab) : ?>
			<button type="button" class="rc-nav-item active" data-tab="home">
				<span class="rc-nav-icon"><?php rapidchat_icon_e('home', 20); ?></span>
				<span class="rc-nav-label"><?php esc_html_e('Home', 'rapidchat'); ?></span>
			</button>
			<?php endif; ?>
			<button type="button" class="rc-nav-item <?php echo !$show_home_tab ? 'active' : ''; ?>" data-tab="messages">
				<span class="rc-nav-icon"><?php rapidchat_icon_e('comment-dots', 20); ?></span>
				<span class="rc-nav-label"><?php esc_html_e('Messages', 'rapidchat'); ?></span>
				<span class="rc-nav-badge" style="display: none;">0</span>
			</button>
			<?php do_action('rapidchat_widget_nav_items'); ?>
		</div>
		<?php endif; ?>

	</div>
</div>
<?php do_action('rapidchat_after_widget_render'); ?>
	<?php
}
