<?php
/** Modern 3-Pane Operator Inbox Shell. */

defined('ABSPATH') || exit;

function rapidchat_inbox_page() {
	?>
<div class="wrap rapidchat-admin-wrap" id="rapidchat-admin">
	<hr class="wp-header-end">
	<div class="chat-widget">

		<div class="chat-widget-header">
			<div class="header-left">
				<img class="rc-inbox-mark" src="<?php echo esc_url(RAPIDCHAT_URL . '/images/logo.png'); ?>" width="36" height="36" alt="" aria-hidden="true">
				<div class="rc-inbox-titles">
					<h1 class="header-title"><?php esc_html_e('Live Inbox', 'rapidchat'); ?></h1>
					<span class="rc-inbox-subtitle"><?php esc_html_e('RapidChat workspace', 'rapidchat'); ?></span>
				</div>
			</div>

			<div class="header-right">
				<nav class="rc-inbox-links" aria-label="<?php esc_attr_e('RapidChat pages', 'rapidchat'); ?>">
					<?php if (current_user_can('rapidchat_view_analytics') && menu_page_url('rapidchat-dashboard', false)) : ?>
						<a class="rc-inbox-link" href="<?php echo esc_url(menu_page_url('rapidchat-dashboard', false)); ?>"><?php rapidchat_icon_e('chart', 16); ?><span><?php esc_html_e('Dashboard', 'rapidchat'); ?></span></a>
					<?php endif; ?>
					<?php if (current_user_can('rapidchat_view_analytics') && menu_page_url('rapidchat-visitors', false)) : ?>
						<a class="rc-inbox-link" href="<?php echo esc_url(menu_page_url('rapidchat-visitors', false)); ?>"><?php rapidchat_icon_e('users', 16); ?><span><?php esc_html_e('Visitors', 'rapidchat'); ?></span></a>
					<?php endif; ?>
				</nav>

				<button type="button" class="rc-inbox-icon-btn rc-inbox-sound-btn" aria-pressed="true"
					title="<?php esc_attr_e('Sound alerts on', 'rapidchat'); ?>" aria-label="<?php esc_attr_e('Sound alerts', 'rapidchat'); ?>"
					data-title-on="<?php esc_attr_e('Sound alerts on', 'rapidchat'); ?>" data-title-off="<?php esc_attr_e('Sound alerts muted', 'rapidchat'); ?>">
					<span class="rc-sound-on"><?php rapidchat_icon_e('volume-up', 18); ?></span>
					<span class="rc-sound-off"><?php rapidchat_icon_e('volume-mute', 18); ?></span>
				</button>
				<?php if (rapidchat_allowed_settings_tabs()) : ?>
					<a href="<?php echo esc_url(admin_url('admin.php?page=rapidchat-settings')); ?>" class="rc-inbox-icon-btn rc-inbox-settings-btn" title="<?php esc_attr_e('Settings', 'rapidchat'); ?>" aria-label="<?php esc_attr_e('Settings', 'rapidchat'); ?>">
						<?php rapidchat_icon_e('cog', 18); ?>
					</a>
				<?php endif; ?>

				<div class="rc-agent-chip">
					<div class="admin-avatar" id="admin-avatar" aria-hidden="true"></div>
					<div class="admin-meta">
						<span class="admin-name" id="admin-name"></span>
						<span class="rc-agent-chip-sub">
							<span class="rc-operator-status-badge rc-status-online" title="<?php esc_attr_e('Operator Status', 'rapidchat'); ?>"><span class="rc-status-dot"></span> <?php esc_html_e('Online', 'rapidchat'); ?></span>
							<span class="role-name" id="role-name"></span>
						</span>
					</div>
				</div>
			</div>
		</div>

		<div class="chat-widget-content">
			<div class="chat-widget-columns">

				<div class="chat-widget-left">
					<div class="rc-inbox-search-bar">
						<input type="text" id="user-search" placeholder="<?php esc_attr_e('Search conversations or tags...', 'rapidchat'); ?>" aria-label="<?php esc_attr_e('Search conversations', 'rapidchat'); ?>">
					</div>
					<div class="rc-filter-tabs" id="rc-inbox-filters">
						<button type="button" class="rc-filter-tab active" data-filter="all"><?php esc_html_e('All', 'rapidchat'); ?></button>
						<button type="button" class="rc-filter-tab" data-filter="unread"><?php esc_html_e('Unread', 'rapidchat'); ?> <span class="rc-tab-badge" id="rc-unread-tab-count" style="display:none;"></span></button>
						<button type="button" class="rc-filter-tab" data-filter="mine"><?php esc_html_e('Mine', 'rapidchat'); ?></button>
						<button type="button" class="rc-filter-tab" data-filter="queued"><?php esc_html_e('Queued', 'rapidchat'); ?></button>
						<button type="button" class="rc-filter-tab" data-filter="closed"><?php esc_html_e('Closed', 'rapidchat'); ?></button>
					</div>
					<div class="msg" role="status"></div>
					<div class="chat-widget-tab chat-widget-conversations-tab"></div>
				</div>

				<div class="chat-widget-right">
					<div class="chat-widget-tab chat-widget-conversation-tab">
						<div class="rc-inbox-empty-state">
							<div class="rc-empty-icon"><?php rapidchat_icon_e('comments', 48); ?></div>
							<h3><?php esc_html_e('Select a conversation to start chatting', 'rapidchat'); ?></h3>
							<p><?php esc_html_e('Choose an active visitor from the left sidebar to open their real-time message thread and details.', 'rapidchat'); ?></p>
						</div>
					</div>
				</div>

			</div>
		</div>

		<div class="rc-canned-autocomplete" id="rc-canned-autocomplete" style="display: none;"></div>

	</div>
</div>
	<?php
}
