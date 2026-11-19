(function($) {
	function hexToRgb(hex) {
		hex = hex.replace('#', '');
		if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
		if (hex.length !== 6) return [30, 60, 114];
		return [
			parseInt(hex.substr(0, 2), 16) || 0,
			parseInt(hex.substr(2, 2), 16) || 0,
			parseInt(hex.substr(4, 2), 16) || 0
		];
	}

	function adjustBrightness(hex, steps) {
		var rgb = hexToRgb(hex);
		var out = rgb.map(function(c) {
			var val = Math.max(0, Math.min(255, c + steps));
			var s = val.toString(16);
			return s.length === 1 ? '0' + s : s;
		});
		return '#' + out.join('');
	}

	function getContrastColor(hex) {
		var rgb = hexToRgb(hex);
		var r = rgb[0] / 255, g = rgb[1] / 255, b = rgb[2] / 255;
		var lum = 0.2126 * (r <= 0.03928 ? r / 12.92 : Math.pow((r + 0.055) / 1.055, 2.4))
			+ 0.7152 * (g <= 0.03928 ? g / 12.92 : Math.pow((g + 0.055) / 1.055, 2.4))
			+ 0.0722 * (b <= 0.03928 ? b / 12.92 : Math.pow((b + 0.055) / 1.055, 2.4));
		return lum > 0.45 ? '#0f172a' : '#ffffff';
	}

	function updatePreview() {
		var hex = $('#rc-primary-color-hex').val() || '#1e3c72';
		if (!/^#[0-9A-F]{6}$/i.test(hex)) {
			hex = $('#rc-primary-color-picker').val() || '#1e3c72';
		}

		var lightHex = adjustBrightness(hex, 28);
		var rgb = hexToRgb(hex);
		var rgbStr = rgb.join(', ');
		var textColor = getContrastColor(hex);
		var isDarkText = textColor === '#0f172a';
		var iconBg = isDarkText ? 'rgba(0, 0, 0, 0.08)' : 'rgba(255, 255, 255, 0.22)';
		var glow = 'rgba(' + rgbStr + ', 0.35)';
		var grad = 'linear-gradient(135deg, ' + hex + ' 0%, ' + lightHex + ' 100%)';

		var container = document.getElementById('rc-preview-container');
		if (container) {
			container.style.setProperty('--rc-prev-primary', hex);
			container.style.setProperty('--rc-prev-gradient', grad);
			container.style.setProperty('--rc-prev-glow', glow);
			container.style.setProperty('--rc-prev-text', textColor);
			container.style.setProperty('--rc-prev-icon-bg', iconBg);
		}

		var mode = $('input[name="rapidchat_widget_mode"]:checked').val() || 'hub';
		var showHome = $('#rc-toggle-home-tab').is(':checked');
		var showHelp = $('#rc-toggle-help-tab').is(':checked');
		var showFaqs = $('#rc-toggle-home-faqs').is(':checked');

		if (mode === 'direct') {
			$('#rc-preview-mode-tag').text((window.rapidchatPreview || {}).directTag || 'Direct Live Chat');
			$('#rc-preview-mode-badge').text((window.rapidchatPreview || {}).direct || 'Direct chat');
			$('#rc-hub-tab-toggles').slideUp(200);
			$('#rc-mockup-home-view').hide();
			$('#rc-mockup-direct-view').show();
			$('#rc-mockup-composer').show();
			$('#rc-mockup-nav').hide();
		} else {
			$('#rc-preview-mode-tag').text((window.rapidchatPreview || {}).hubTag || 'Self-Service Hub');
			$('#rc-preview-mode-badge').text((window.rapidchatPreview || {}).hub || 'Hub');
			$('#rc-hub-tab-toggles').slideDown(200);
			if (showHome) {
				$('#rc-mockup-home-view').show();
				$('#rc-mockup-direct-view').hide();
				$('#rc-mockup-composer').hide();
			} else {
				$('#rc-mockup-home-view').hide();
				$('#rc-mockup-direct-view').show();
				$('#rc-mockup-composer').show();
			}
			$('#rc-mockup-nav').show();
			if (showHome) $('#rc-mockup-nav-home').show(); else $('#rc-mockup-nav-home').hide();
			if (showHelp) $('#rc-mockup-nav-help').show(); else $('#rc-mockup-nav-help').hide();
			if (showFaqs && showHome) $('#rc-mockup-faqs-preview').show(); else $('#rc-mockup-faqs-preview').hide();
		}

		var brandTitle = $('#rc-brand-title-input').val() || 'Support Chat';
		var greetingTitle = $('#rc-greeting-title-input').val() || 'Hi there 👋';
		var greetingDesc = $('#rc-greeting-desc-input').val() || 'How can we help you today?';
		var actionTitle = $('#rc-action-title-input').val() || 'Send us a message';
		var actionSub = $('#rc-action-sub-input').val() || 'We reply in minutes ⚡';
		var pos = $('#rc-widget-pos-select').val() || 'right';

		$('#rc-mockup-title').text(brandTitle);
		$('#rc-mockup-welcome-title').text(greetingTitle);
		$('#rc-mockup-welcome-desc').text(greetingDesc);
		$('#rc-mockup-card-title').text(actionTitle);
		$('#rc-mockup-card-sub').text(actionSub);

		if (pos === 'left') {
			$('#rc-preview-container').addClass('pos-left');
		} else {
			$('#rc-preview-container').removeClass('pos-left');
		}

		$('.rc-mode-card').removeClass('active');
		$('input[name="rapidchat_widget_mode"]:checked').closest('.rc-mode-card').addClass('active');

		$('.rc-preset-chip').each(function() {
			var chipColor = $(this).data('color').toLowerCase();
			if (chipColor === hex.toLowerCase()) {
				$(this).addClass('active');
			} else {
				$(this).removeClass('active');
			}
		});
	}

	$(document).ready(function() {
		$('input[name="rapidchat_widget_mode"]').on('change', updatePreview);

		$('#rc-toggle-home-tab, #rc-toggle-help-tab, #rc-toggle-home-faqs').on('change', updatePreview);

		$('#rc-primary-color-picker').on('input change', function() {
			var val = $(this).val();
			$('#rc-primary-color-hex').val(val);
			updatePreview();
		});

		$('#rc-primary-color-hex').on('input change', function() {
			var val = $(this).val();
			if (/^#[0-9A-F]{6}$/i.test(val)) {
				$('#rc-primary-color-picker').val(val);
				updatePreview();
			}
		});

		$('.rc-preset-chip').on('click', function(e) {
			e.preventDefault();
			var color = $(this).data('color');
			$('#rc-primary-color-picker').val(color);
			$('#rc-primary-color-hex').val(color);
			updatePreview();
		});

		$('#rc-brand-title-input, #rc-greeting-title-input, #rc-greeting-desc-input, #rc-action-title-input, #rc-action-sub-input, #rc-widget-pos-select').on('input change', updatePreview);

		updatePreview();
	});
})(jQuery);
