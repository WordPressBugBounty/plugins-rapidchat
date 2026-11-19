/** RapidChat visitor widget. */
(function ($) {
	'use strict';

	var EMOJIS = ['👋', '😊', '👍', '❤️', '🔥', '🎉', '🙌', '💡', '✨', '🚀', '💯', '🙏', '😃', '🤔', '😍', '👏', '🎯', '⭐', '🤝', '😎', '💬', '📞', '📧', '💼'];

	var state = {
		currentTab: 'home',
		conversationId: null,
		lastMessageId: 0,
		fingerprint: '',
		lastRenderedDate: '',
		isTyping: false,
		currentDraft: '',
		typingTimer: null,
		inFlight: false,
		timer: null,
		started: false,
		soundEnabled: true,
		selectedRating: 0,
		pendingAttachment: null,
		forceNew: false,
		pendingSeq: 0,
		retryQueue: {},
		unreadTitleCount: 0,
		originalTitle: document.title,
		drafts: {}
	};

	function getVisitorContext() {
		var ua = navigator.userAgent || '';
		var browser = 'Browser';
		if (ua.indexOf('Firefox') > -1) browser = 'Firefox';
		else if (ua.indexOf('Chrome') > -1) browser = 'Chrome';
		else if (ua.indexOf('Safari') > -1) browser = 'Safari';
		else if (ua.indexOf('Edge') > -1) browser = 'Edge';

		var os = 'OS';
		if (ua.indexOf('Win') > -1) os = 'Windows';
		else if (ua.indexOf('Mac') > -1) os = 'macOS';
		else if (ua.indexOf('Linux') > -1) os = 'Linux';
		else if (ua.indexOf('Android') > -1) os = 'Android';
		else if (ua.indexOf('iPhone') > -1 || ua.indexOf('iPad') > -1) os = 'iOS';

		var device = /Mobi|Android|iPhone|iPad/i.test(ua) ? 'Mobile' : 'Desktop';

		var timezone = '';
		try {
			timezone = (window.Intl && Intl.DateTimeFormat().resolvedOptions().timeZone) || '';
		} catch (e) { }

		return {
			url: window.location.href,
			title: document.title || '',
			referrer: document.referrer || '',
			browser: browser,
			os: os,
			device: device,
			locale: navigator.language || '',
			timezone: timezone
		};
	}

	var audioCtx = null;

	function playChime() {
		if (!state.soundEnabled) {
			return;
		}
		try {
			var AudioContext = window.AudioContext || window.webkitAudioContext;
			if (!AudioContext) {
				return;
			}
			if (!audioCtx) {
				audioCtx = new AudioContext();
			}
			if (audioCtx.state === 'suspended') {
				audioCtx.resume();
			}

			var now = audioCtx.currentTime;
			var osc = audioCtx.createOscillator();
			var gain = audioCtx.createGain();

			osc.type = 'sine';
			osc.frequency.setValueAtTime(587.33, now);
			osc.frequency.exponentialRampToValueAtTime(880, now + 0.12);

			gain.gain.setValueAtTime(0.15, now);
			gain.gain.exponentialRampToValueAtTime(0.001, now + 0.35);

			osc.connect(gain);
			gain.connect(audioCtx.destination);

			osc.start(now);
			osc.stop(now + 0.35);
		} catch (e) {}
	}

	function notifyDesktop(title, body) {
		if (!('Notification' in window)) {
			return;
		}
		if (Notification.permission === 'granted') {
			try {
				new Notification(title, { body: body });
			} catch (e) {}
		}
	}

	function requestNotificationPermission() {
		if ('Notification' in window && Notification.permission === 'default') {
			Notification.requestPermission();
		}
	}

	function flashTitle() {
		state.unreadTitleCount++;
		document.title = '(' + state.unreadTitleCount + ') New message · ' + state.originalTitle;
	}

	function resetTitle() {
		if (state.unreadTitleCount > 0) {
			state.unreadTitleCount = 0;
			document.title = state.originalTitle;
		}
	}

	function post(action, data) {
		return $.ajax({
			url: rapidchat_ajax.ajax_url,
			method: 'POST',
			cache: false,
			data: $.extend({ action: action, rapidchat_nonce: rapidchat_ajax.rapidchat_nonce }, data || {})
		});
	}

	function isError(response) {
		return !response || (typeof response === 'object' && response.success === false);
	}

	function errorText(response, fallback) {
		if (response && typeof response === 'object' && response.data) {
			return typeof response.data === 'string' ? response.data : fallback;
		}
		return fallback;
	}

	function failText(xhr, fallback) {
		return errorText(xhr && xhr.responseJSON, fallback);
	}

	function switchTab(tabName) {
		state.currentTab = tabName;

		var $root = $('#rapidchat-widget');
		var widgetMode = (window.rapidchat_ajax && window.rapidchat_ajax.widget_mode) || $root.data('widget-mode') || 'hub';
		var showHome = (window.rapidchat_ajax && typeof window.rapidchat_ajax.show_home !== 'undefined') ? parseInt(window.rapidchat_ajax.show_home, 10) : parseInt($root.data('show-home'), 10);
		if (isNaN(showHome)) showHome = 1;

		$('.rc-tab-view').hide();
		$('.rc-bottom-nav .rc-nav-item').removeClass('active');
		$('.rc-bottom-nav .rc-nav-item[data-tab="' + tabName + '"]').addClass('active');

		if (tabName === 'home') {
			$('.rc-tab-home').css('display', 'flex');
			$('.rc-header-back-btn').hide();
		} else if (tabName === 'login') {
			$('.rc-tab-login').css('display', 'flex');
			populateSavedVisitor();
			if (widgetMode === 'direct' || !showHome) {
				$('.rc-header-back-btn').hide();
			} else {
				$('.rc-header-back-btn').show();
			}
		} else if (tabName === 'messages') {
			if (state.conversationId) {
				state.currentTab = 'thread';
				$('.rc-tab-thread').css('display', 'flex');
				if (widgetMode === 'direct' || !showHome) {
					$('.rc-header-back-btn').hide();
				} else {
					$('.rc-header-back-btn').show();
				}
				schedulePoll(0);
			} else {
				loadConversations();
			}
		} else if (tabName === 'conversations') {
			$('.rc-bottom-nav .rc-nav-item[data-tab="messages"]').addClass('active');
			loadConversations();
		} else if (tabName === 'thread') {
			$('.rc-tab-thread').css('display', 'flex');
			if (widgetMode === 'direct' || !showHome) {
				$('.rc-header-back-btn').hide();
			} else {
				$('.rc-header-back-btn').show();
			}
		} else {
			$('.rc-tab-' + tabName).css('display', 'flex');
			$('.rc-header-back-btn').hide();
		}

		if (tabName === 'thread') {
			schedulePoll(0);
		}

		$(document).trigger('rapidchat:tab', [tabName]);
	}

	function messagesContainer() {
		return $('.chat-widget-conversation-tab .chat-widget-messages');
	}

	function ensureTypingElement(container) {
		var el = container.find('.chat-typing-indicator');
		if (!el.length) {
			el = $('<div>', { 'class': 'chat-typing-indicator' })
				.append($('<span>', { 'class': 'typing-dot' }))
				.append($('<span>', { 'class': 'typing-dot' }))
				.append($('<span>', { 'class': 'typing-dot' }))
				.appendTo(container);
		}
		return el;
	}

	function setTypingDisplay(isTyping, userName) {
		var container = messagesContainer();
		if (!container.length) {
			return;
		}
		var typingEl = ensureTypingElement(container);
		if (isTyping) {
			typingEl.css('display', 'inline-flex');
			scrollToBottom(container);
		} else {
			typingEl.css('display', 'none');
		}
	}

	/** Extend the run above instead of starting a new one. */
	/** The newest bubble, skipping the caption paragraph that follows it. */
	function lastBubble(container) {
		return container.children('.chat-widget-message, .chat-message-wrapper').last();
	}

	function continuesRun(container, row) {
		var last = lastBubble(container);

		if (!last.length) {
			return false;
		}

		if (last.nextAll('.date-center').length) {
			return false;
		}

		var bubble = last.hasClass('chat-message-wrapper')
			? last.find('.chat-widget-message')
			: last;

		if (!bubble.length) {
			return false;
		}

		if ((bubble.attr('data-author') || '') !== (row.author || '')) {
			return false;
		}

		if (!!bubble.attr('data-mine') !== !!row.mine) {
			return false;
		}

		var gap = Math.abs((row.ts || 0) - (parseInt(bubble.attr('data-ts'), 10) || 0));

		return gap <= 300;
	}

	function escHtml(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function linkify(text, markdown) {
		var html = escHtml(text);
		if (markdown) {
			html = html
				.replace(/`([^`\n]+)`/g, '<code>$1</code>')
				.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
		}
		return html
			.replace(/(https?:\/\/[^\s<"']+[^\s<"'.,:;!?)\]}])/g, function (url) {
				return '<a href="' + url + '" target="_blank" rel="noopener nofollow">' + url + '</a>';
			})
			.replace(/\n/g, '<br>');
	}

	var TOUCH = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;

	function autogrow(el) {
		if (!el) { return; }
		el.style.height = 'auto';
		el.style.height = Math.min(el.scrollHeight, 120) + 'px';
		el.style.overflowY = el.scrollHeight > 120 ? 'auto' : 'hidden';
	}

	function resetComposer(el) {
		if (!el) { return; }
		el.value = '';
		el.style.height = 'auto';
		el.style.overflowY = 'hidden';
	}

	function appendMessage(row) {
		var container = messagesContainer();
		if (!container.length) {
			return;
		}

		container.find('.chat-typing-indicator').remove();

		if (row.date && row.date !== state.lastRenderedDate) {
			$('<p>', { 'class': 'date-center', 'data-date': row.date })
				.text(dateLabel(row.date))
				.appendTo(container);
			state.lastRenderedDate = row.date;
		}

		var extending = continuesRun(container, row);

		if (extending) {
			var previous = lastBubble(container);

			container.children('.msg-time').last().remove();
			previous.find('.chat-icon').addClass('rc-icon-spacer').text('');
			(previous.hasClass('chat-message-wrapper')
				? previous.find('.chat-widget-message')
				: previous
			).removeClass('rc-group-end');
		}

		var bubble = $('<div>', {
			'class': 'chat-widget-message' + (row.mine ? '' : ' alt')
				+ (extending ? '' : ' rc-group-start') + ' rc-group-end',
			title: row.time
		})
			.attr('data-message-id', row.id)
			.attr('data-author', row.author || '')
			.attr('data-ts', row.ts || 0)
			.attr('data-mine', row.mine ? '1' : '');

		if (row.msg) {
			bubble.append($('<div>', { 'class': 'message-text' }).html(linkify(row.msg, !row.mine)));
		}

		if (row.attachment_url) {
			if (row.attachment_type === 'image') {
				var imgWrap = $('<div>', { 'class': 'rc-attachment-image' });
				$('<a>', { href: row.attachment_url, target: '_blank', rel: 'noopener noreferrer' })
					.append($('<img>', { src: row.attachment_url, alt: row.attachment_name || 'Image' }))
					.appendTo(imgWrap);
				bubble.append(imgWrap);
			} else {
				var fileWrap = $('<div>', { 'class': 'rc-attachment-file' });
				var fileLink = $('<a>', { href: row.attachment_url, target: '_blank', rel: 'noopener noreferrer', 'class': 'rc-file-link' })
					.append($('<span>').text('📄 ' + (row.attachment_name || 'Download File')));
				if (row.attachment_size) {
					fileLink.append($('<span>', { 'class': 'rc-file-size' }).text(formatFileSize(row.attachment_size)));
				}
				fileLink.appendTo(fileWrap);
				bubble.append(fileWrap);
			}
		}

		if (row.mine) {
			container.append(bubble);
		} else {
			$('<div>', { 'class': 'chat-message-wrapper' })
				.append($('<span>', { 'class': 'chat-icon' }).text(initial(row.author)))
				.append(bubble)
				.appendTo(container);
		}

		var meta = row.time + (!row.mine && row.author ? ' · ' + row.author : '');
		$('<p>', { 'class': 'msg-time' + (row.mine ? '' : ' alt') }).text(meta).appendTo(container);
	}

	function initial(name) {
		return name ? name.charAt(0).toUpperCase() : '?';
	}

	function formatFileSize(bytes) {
		bytes = parseInt(bytes, 10) || 0;
		var units = ['B', 'KB', 'MB', 'GB'];
		var i = 0;
		while (bytes >= 1024 && i < units.length - 1) {
			bytes /= 1024;
			i++;
		}
		return (i === 0 ? bytes : bytes.toFixed(bytes < 10 ? 1 : 0)) + ' ' + units[i];
	}

	function updateSeenReceipt(readThrough) {
		var container = messagesContainer();
		if (!container.length) {
			return;
		}

		container.find('.rc-seen-receipt').remove();

		readThrough = parseInt(readThrough, 10) || 0;
		if (!readThrough) {
			return;
		}

		var lastMine = container.find('.chat-widget-message[data-mine="1"]').last();
		var lastId = lastMine.length ? parseInt(lastMine.attr('data-message-id'), 10) : 0;

		if (lastId > 0 && lastId <= readThrough) {
			var anchor = lastMine.nextAll('.msg-time').first();
			(anchor.length ? anchor : lastMine).after($('<p>', { 'class': 'rc-seen-receipt' }).text('Seen'));
		}
	}

	function removePendingBubble(clientId) {
		var bubble = messagesContainer().find('[data-message-id="' + clientId + '"]');
		if (!bubble.length) {
			return;
		}
		bubble.next('.msg-time').remove();
		bubble.remove();
	}

	function appendPendingMessage(clientId, text, att) {
		appendMessage({
			id: clientId,
			msg: text,
			mine: true,
			author: '',
			ts: Math.floor(Date.now() / 1000),
			time: 'Sending…',
			date: todayStamp(),
			attachment_url: att ? att.url : '',
			attachment_type: att ? att.type : '',
			attachment_name: att ? att.name : ''
		});
		messagesContainer().find('[data-message-id="' + clientId + '"]').attr('data-pending', '1');
		updateSeenReceipt(0);
		scrollToBottom(messagesContainer());
	}

	function markBubbleSent(clientId) {
		var bubble = messagesContainer().find('[data-message-id="' + clientId + '"]');
		bubble.removeAttr('data-pending').attr('data-sent', '1');
		bubble.next('.msg-time').text('Sent');
	}

	function markBubbleFailed(clientId, message) {
		var bubble = messagesContainer().find('[data-message-id="' + clientId + '"]');
		bubble.removeAttr('data-pending').attr('data-failed', '1');
		bubble.next('.msg-time').html(
			escHtml(message || 'Failed to send') + ' · <button type="button" class="rc-retry-btn" data-pending-id="' + clientId + '">Retry</button>'
		);
	}

	function submitMessage(form, text, att) {
		var $form = $(form);
		var conversationId = $form.find('input[name="id"]').val();

		if (!conversationId || (!text && !att)) {
			return;
		}

		var clientId = 'pending-' + (++state.pendingSeq);
		state.retryQueue[clientId] = { form: form, text: text, att: att };
		appendPendingMessage(clientId, text, att);

		post('rapidchat_post_message', {
			id: conversationId,
			msg: text,
			attachment_url: att ? att.url : '',
			attachment_type: att ? att.type : '',
			attachment_name: att ? att.name : '',
			attachment_size: att ? att.size : 0
		})
			.done(function (response) {
				if (response && response.success) {
					delete state.retryQueue[clientId];
					markBubbleSent(clientId);
					schedulePoll(0);
				} else {
					markBubbleFailed(clientId, errorText(response, 'Unable to send message.'));
				}
			})
			.fail(function (xhr) {
				markBubbleFailed(clientId, failText(xhr, 'Error submitting message.'));
			});
	}

	function dateLabel(stamp) {
		var parts = String(stamp || '').split('/');
		if (parts.length !== 3) {
			return stamp || '';
		}
		var day = new Date(+parts[0], +parts[1] - 1, +parts[2]);
		var today = new Date();
		today.setHours(0, 0, 0, 0);
		var diff = Math.round((today - day) / 86400000);
		if (diff === 0) {
			return 'Today';
		}
		if (diff === 1) {
			return 'Yesterday';
		}
		return day.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
	}

	function todayStamp() {
		var d = new Date();
		var m = ('0' + (d.getMonth() + 1)).slice(-2);
		var day = ('0' + d.getDate()).slice(-2);
		return d.getFullYear() + '/' + m + '/' + day;
	}

	/** Swap the composer for the ended notice and offer the rating card. */
	function populateSavedVisitor() {
		try {
			var savedName = localStorage.getItem('rapidchat_guest_name');
			var savedEmail = localStorage.getItem('rapidchat_guest_email');
			if (savedName && !$('#rc-login-name').val()) {
				$('#rc-login-name').val(savedName);
			}
			if (savedEmail && !$('#rc-login-email').val()) {
				$('#rc-login-email').val(savedEmail);
			}
		} catch (e) {}
	}

	function markChatEnded() {
		var composer = $('.chat-widget-input-message');

		if (composer.length) {
			composer.replaceWith(
				$('<div>', { 'class': 'chat-session-ended' })
					.append($('<p>').text('Chat session has ended.'))
					.append($('<button>', { 'class': 'rc-start-new-chat-btn rc-btn-primary', 'type': 'button' }).text('Start New Conversation'))
			);
		}

		$('.rc-rating-card').slideDown();
	}

	function isPinnedToBottom(container) {
		if (!container.length) {
			return true;
		}
		var node = container[0];
		return node.scrollHeight - node.scrollTop - node.clientHeight < 60;
	}

	function scrollToBottom(container) {
		if (container && container.length) {
			container.scrollTop(container[0].scrollHeight);
		}
	}

	function openLightbox(src, alt) {
		var box = $('.rc-lightbox');
		if (!box.length) {
			box = $('<div>', { 'class': 'rc-lightbox' })
				.append($('<img>', { 'class': 'rc-lightbox-img' }))
				.append($('<button>', { type: 'button', 'class': 'rc-lightbox-close', 'aria-label': 'Close preview' }).html('&times;'))
				.appendTo('#rapidchat-widget');
		}
		box.find('.rc-lightbox-img').attr({ src: src, alt: alt || '' });
		box.addClass('open');
	}

	function closeLightbox() {
		$('.rc-lightbox').removeClass('open');
	}

	function syncCursorFromDom() {
		var ids = messagesContainer().find('[data-message-id]').map(function () {
			return parseInt(this.getAttribute('data-message-id'), 10) || 0;
		}).get();

		state.lastMessageId = ids.length ? Math.max.apply(null, ids) : 0;

		var dates = messagesContainer().find('.date-center');
		state.lastRenderedDate = dates.length ? (dates.last().attr('data-date') || '') : '';
	}

	function updatePresenceBadge(isOnline) {
		var dot = $('.rc-status-dot');
		var text = $('#rapidchat-presence-text');
		if (isOnline) {
			dot.removeClass('offline').addClass('online');
			text.text('Online • Typically replies in minutes');
		} else {
			dot.removeClass('online').addClass('offline');
			text.text('Away • Leave a message');
		}
	}

	function loadConversations() {
		state.currentTab = 'conversations';
		post('rapidchat_conversations_user').done(function (response) {
			if (isError(response)) {
				switchTab('login');
				return;
			}
			$('.rc-tab-view').hide();
			$('.chat-widget-conversations-tab').html(response);
			$('.rc-tab-conversations').css('display', 'flex');
			$('.rc-header-back-btn').show();
		}).fail(function () {
			switchTab('login');
		});
	}

	function saveComposerDraft(convId) {
		if (!convId) {
			return;
		}
		var text = $('.chat-widget-input-message [name="msg"]').val() || '';
		if (text || state.pendingAttachment) {
			state.drafts[convId] = { text: text, attachment: state.pendingAttachment };
		} else {
			delete state.drafts[convId];
		}
	}

	function restoreComposerDraft(convId) {
		var draft = state.drafts[convId];
		if (!draft) {
			return;
		}
		var input = $('.chat-widget-input-message [name="msg"]');
		input.val(draft.text);
		autogrow(input[0]);
		if (draft.attachment) {
			state.pendingAttachment = draft.attachment;
			showAttachmentPreview(draft.attachment);
		}
	}

	function openConversation(id) {
		if (state.conversationId === id) {
			switchTab('thread');
			return;
		}

		saveComposerDraft(state.conversationId);
		state.pendingAttachment = null;

		state.conversationId = id;
		state.lastMessageId = 0;
		state.lastRenderedDate = '';

		$('.rc-rating-card').hide();
		$('.rc-rating-thankyou').hide();
		$('.rc-star-rating, .rc-feedback-input, .rc-submit-rating-btn').show();
		$('.rc-star').removeClass('selected');
		state.selectedRating = 0;

		post('rapidchat_conversation_user', { conversation_id: id }).done(function (response) {
			if (isError(response)) {
				window.alert(errorText(response, 'This conversation is no longer available.'));
				switchTab('home');
				return;
			}

			$('.chat-widget-conversation-tab').html(response);
			restoreComposerDraft(id);
			syncCursorFromDom();
			updateSeenReceipt(messagesContainer().data('read-through'));
			switchTab('thread');
			scrollToBottom(messagesContainer());
			initEmojiPicker();
		});
	}

	function findOrCreateConversation(fields, initialMessage, forceNew) {
		var payload = $.extend({}, fields || {}, {
			context: getVisitorContext()
		});
		if (initialMessage) {
			payload.initial_message = initialMessage;
		}
		if (forceNew || state.forceNew) {
			payload.force_new = 1;
		}

		function showLoginError(text) {
			switchTab('login');
			$('#chat-login-form .msg').text(text);
		}

		post('rapidchat_find_conversation', payload).done(function (response) {
			if (response && response.success && response.data && response.data.id) {
				state.forceNew = false;
				openConversation(response.data.id);
			} else {
				showLoginError(errorText(response, 'Could not start a conversation. Please try again.'));
			}
		}).fail(function (xhr) {
			showLoginError(failText(xhr, 'Server connection error. Please try again.'));
		});
	}

	function sendMessage(form) {
		var $form = $(form);
		var input = $form.find('[name="msg"]');
		var text = $.trim(input.val());
		var att = state.pendingAttachment;

		if (!$form.find('input[name="id"]').val() || (!text && !att)) {
			return;
		}

		state.isTyping = false;

		resetComposer(input[0]);
		clearAttachmentPreview();
		input.trigger('focus');

		submitMessage(form, text, att);
	}

	function handleFileUpload(file) {
		if (!file) {
			return;
		}

		var formData = new FormData();
		formData.append('action', 'rapidchat_upload_attachment');
		formData.append('rapidchat_nonce', rapidchat_ajax.rapidchat_nonce);
		formData.append('file', file);

		var previewBox = $('.rc-composer-preview');
		previewBox.html('<span>Uploading ' + escHtml(file.name) + '...</span>').show();

		$.ajax({
			url: rapidchat_ajax.ajax_url,
			method: 'POST',
			data: formData,
			processData: false,
			contentType: false
		}).done(function (response) {
			if (response && response.success && response.data) {
				state.pendingAttachment = response.data;
				showAttachmentPreview(response.data);
			} else {
				previewBox.hide();
				window.alert(errorText(response, 'Failed to upload attachment.'));
			}
		}).fail(function (xhr) {
			previewBox.hide();
			window.alert(failText(xhr, 'Upload error. Please try again.'));
		});
	}

	function showAttachmentPreview(attachment) {
		$('.rc-composer-preview').html(
			'<span>📎 ' + escHtml(attachment.name) + '</span>' +
			'<button type="button" class="rc-composer-preview-close" aria-label="Remove">&times;</button>'
		).show();
	}

	function clearAttachmentPreview() {
		state.pendingAttachment = null;
		$('.rc-composer-preview').empty().hide();
		$('.rc-file-input').val('');
	}

	function insertAtCursor(field, text) {
		if (!field) {
			return;
		}

		var value = field.value;
		var start = typeof field.selectionStart === 'number' ? field.selectionStart : value.length;
		var end = typeof field.selectionEnd === 'number' ? field.selectionEnd : start;

		field.value = value.slice(0, start) + text + value.slice(end);
		field.selectionStart = field.selectionEnd = start + text.length;
	}

	function initEmojiPicker() {
		var picker = $('.rc-emoji-picker-dropdown');
		if (!picker.length || picker.children().length > 0) {
			return;
		}

		$.each(EMOJIS, function (_, emoji) {
			$('<span>', { 'class': 'rc-emoji-item' })
				.text(emoji)
				.appendTo(picker);
		});
	}

	function schedulePoll(delay) {
		window.clearTimeout(state.timer);
		state.timer = window.setTimeout(poll, delay);
	}

	function poll() {
		if (state.inFlight || document.hidden || state.currentTab !== 'thread' || !state.conversationId) {
			return;
		}

		state.inFlight = true;

		post('rapidchat_poll', {
			conversation_id: state.conversationId || 0,
			since_id: state.lastMessageId,
			fingerprint: state.fingerprint,
			typing: state.isTyping ? 1 : 0,
			peek: state.currentDraft || ''
		}).done(function (response) {
			if (!response || !response.success) {
				return;
			}

			var data = response.data;
			var container = messagesContainer();
			var pinned = isPinnedToBottom(container);

			if (typeof data.operators_online !== 'undefined') {
				updatePresenceBadge(data.operators_online);
			}

			if (data.messages && data.messages.length) {
				messagesContainer().find('[data-sent="1"]').each(function () {
					$(this).next('.msg-time').remove();
					$(this).remove();
				});

				var hasIncoming = false;
				$.each(data.messages, function (_, row) {
					appendMessage(row);
					if (!row.mine) {
						hasIncoming = true;
						if (document.hidden) {
							notifyDesktop(row.author || 'Support', row.msg);
							flashTitle();
						}
					}
				});
				state.lastMessageId = data.last_id;

				if (hasIncoming) {
					playChime();
				}

				if (pinned) {
					scrollToBottom(messagesContainer());
				}
			}

			setTypingDisplay(!!data.typing, data.typing_user || '');
			updateSeenReceipt(data.read_through);

			if (data.state === 'closed') {
				markChatEnded();
			}

			schedulePoll(data.next_delay || 15000);
		}).fail(function () {
			schedulePoll(30000);
		}).always(function () {
			state.inFlight = false;
		});
	}

	function openWidget() {
		var widget = $('.chat-widget');
		var launcher = $('.open-chat-widget');

		widget.css('display', 'flex').addClass('open');
		$('#rapidchat-widget').addClass('rc-widget-open');
		launcher.find('.rc-launcher-open-icon').hide();
		launcher.find('.rc-launcher-close-icon').show();
		launcher.find('.rc-unread-badge').hide();
		requestNotificationPermission();
		$(document).trigger('rapidchat:opened');

		if (!state.started) {
			state.started = true;
			var $root = $('#rapidchat-widget');
			var widgetMode = (window.rapidchat_ajax && window.rapidchat_ajax.widget_mode) || $root.data('widget-mode') || 'hub';
			var showHome = (window.rapidchat_ajax && typeof window.rapidchat_ajax.show_home !== 'undefined') ? parseInt(window.rapidchat_ajax.show_home, 10) : parseInt($root.data('show-home'), 10);
			if (isNaN(showHome)) showHome = 1;

			if (widgetMode === 'direct' || !showHome) {
				if (state.conversationId) {
					switchTab('thread');
				} else {
					resumeOrShow('login');
				}
			} else {
				if (state.conversationId) {
					switchTab('thread');
				} else {
					resumeOrShow('home');
				}
			}
		}
	}

	function resumeOrShow(fallbackTab) {
		switchTab(fallbackTab);
		post('rapidchat_find_conversation', { context: getVisitorContext(), find_only: 1 }).done(function (response) {
			if (!isError(response) && response.data && response.data.id && state.currentTab === fallbackTab) {
				openConversation(response.data.id);
			}
		});
	}

	function closeWidget() {
		var widget = $('.chat-widget');
		var launcher = $('.open-chat-widget');

		widget.removeClass('open');
		$('#rapidchat-widget').removeClass('rc-widget-open');
		window.setTimeout(function () { widget.css('display', 'none'); }, 250);
		launcher.find('.rc-launcher-open-icon').show();
		launcher.find('.rc-launcher-close-icon').hide();
		window.clearTimeout(state.timer);
	}

	window.RapidChat = {
		post: post,
		openWidget: openWidget,
		switchTab: switchTab,
		playChime: playChime,
		state: state
	};

	$(function () {
		$(document)
			.on('click', '.open-chat-widget', function (e) {
				e.preventDefault();
				if ($('.chat-widget').hasClass('open')) {
					closeWidget();
				} else {
					openWidget();
				}
			})
			.on('click', '.close-chat-widget-btn', function (e) {
				e.preventDefault();
				closeWidget();
			})
			.on('click', '.rc-bottom-nav .rc-nav-item', function () {
				switchTab($(this).data('tab'));
			})
			.on('click', '.rc-header-back-btn', function () {
				var $root = $('#rapidchat-widget');
				var widgetMode = (window.rapidchat_ajax && window.rapidchat_ajax.widget_mode) || $root.data('widget-mode') || 'hub';
				var showHome = (window.rapidchat_ajax && typeof window.rapidchat_ajax.show_home !== 'undefined') ? parseInt(window.rapidchat_ajax.show_home, 10) : parseInt($root.data('show-home'), 10);
				if (isNaN(showHome)) showHome = 1;

				if (state.currentTab === 'thread') {
					switchTab('conversations');
				} else {
					if (showHome && widgetMode === 'hub') {
						switchTab('home');
					} else {
						switchTab('conversations');
					}
				}
			})
			.on('click', '.rc-start-chat-card-btn', function () {
				findOrCreateConversation();
			})
			.on('click', '.chat-widget-new-conversation, .rc-start-new-chat-btn', function (e) {
				e.preventDefault();
				$('.chat-menu').prop('hidden', true);
				state.conversationId = 0;
				state.forceNew = true;
				switchTab('login');
				populateSavedVisitor();
				$('#rc-login-msg').val('').trigger('focus');
			})
			.on('submit', '#chat-login-form', function (e) {
				e.preventDefault();
				var form = $(this);
				var box = form.find('.msg').text('');
				var name = form.find('input[name="name"]').val();
				var email = form.find('input[name="email"]').val();
				var fields = {};
				form.find('[data-rapidchat-field]').each(function () {
					fields[$(this).attr('name')] = $(this).val();
				});
				var initialMsg = form.find('textarea[name="initial_message"]').val() || '';

				try {
					if (name) localStorage.setItem('rapidchat_guest_name', name);
					if (email) localStorage.setItem('rapidchat_guest_email', email);
				} catch (err) {}

				post('rapidchat_authenticate_user', {
					name: name,
					email: email
				}).done(function (response) {
					if (response && response.success) {
						findOrCreateConversation(fields, initialMsg, state.forceNew);
					} else {
						box.text(errorText(response, 'Could not authenticate.'));
					}
				}).fail(function (xhr) {
					box.text(failText(xhr, 'Server connection error. Please try again.'));
				});
			})
			.on('click', '.chat-widget-conversation', function (e) {
				e.preventDefault();
				openConversation($(this).data('id'));
			})
			.on('click', '.rc-sound-toggle-btn', function () {
				state.soundEnabled = !state.soundEnabled;
				$(this).css('opacity', state.soundEnabled ? '1' : '0.5');
			})
			.on('click', '.chat-menu-btn', function (e) {
				e.preventDefault();
				var menu = $(this).siblings('.chat-menu');
				var open = !menu.prop('hidden');
				menu.prop('hidden', open);
			})
			.on('click', '.rc-send-transcript-btn', function (e) {
				e.preventDefault();
				$('.chat-menu').prop('hidden', true);
				if (!state.conversationId) {
					return;
				}
				post('rapidchat_send_transcript', { id: state.conversationId }).done(function (response) {
					window.alert(response && response.success ? 'Transcript emailed!' : 'Failed to send transcript.');
				});
			})
			.on('click', '.close-chat-session-btn', function (e) {
				e.preventDefault();
				$('.chat-menu').prop('hidden', true);
				$('.chat-confirm-box').fadeIn();
			})
			.on('click', '.cancel-close-chat-btn', function () {
				$('.chat-confirm-box').fadeOut();
			})
			.on('click', '.confirm-close-chat-btn', function () {
				post('rapidchat_destroy_chat_session').done(function () {
					$('.chat-confirm-box').fadeOut();
					markChatEnded();
				});
			})
			.on('click', '.rc-emoji-btn', function (e) {
				e.preventDefault();
				$('.rc-emoji-picker-dropdown').toggle();
			})
			.on('click', '.rc-emoji-item', function () {
				var input = $('.chat-widget-input-message [name="msg"]');
				insertAtCursor(input[0], $(this).text());
				autogrow(input[0]);
				$('.rc-emoji-picker-dropdown').hide();
				input.trigger('focus');
			})
			.on('change', '.rc-file-input', function () {
				if (this.files && this.files[0]) {
					handleFileUpload(this.files[0]);
				}
			})
			.on('click', '.rc-composer-preview-close', function () {
				clearAttachmentPreview();
			})
			.on('input', '.chat-widget-input-message [name="msg"]', function () {
				autogrow(this);
				state.isTyping = true;
				state.currentDraft = $(this).val();
				window.clearTimeout(state.typingTimer);
				state.typingTimer = window.setTimeout(function () {
					state.isTyping = false;
					state.currentDraft = '';
				}, 3000);
			})
			.on('keydown', '.chat-widget-input-message [name="msg"]', function (e) {
				if (e.key === 'Enter' && !e.shiftKey && !TOUCH) {
					e.preventDefault();
					sendMessage($(this).closest('form')[0]);
				}
			})
			.on('submit', '.chat-widget-input-message', function (e) {
				e.preventDefault();
				sendMessage(this);
			})
			.on('click', '.rc-retry-btn', function () {
				var clientId = $(this).data('pending-id');
				var entry = state.retryQueue[clientId];
				if (!entry) {
					return;
				}
				delete state.retryQueue[clientId];
				removePendingBubble(clientId);
				submitMessage(entry.form, entry.text, entry.att);
			})
			.on('click', '.rc-star', function () {
				var rating = $(this).data('rating');
				state.selectedRating = rating;
				$('.rc-star').each(function (i) {
					$(this).toggleClass('selected', i < rating);
				});
			})
			.on('click', '.rc-rating-close', function () {
				$('.rc-rating-card').slideUp();
			})
			.on('click', '.rc-submit-rating-btn', function () {
				if (!state.selectedRating || !state.conversationId) {
					return;
				}
				post('rapidchat_rate_conversation', {
					id: state.conversationId,
					rating: state.selectedRating,
					feedback: $('.rc-feedback-input').val()
				}).done(function () {
					$('.rc-star-rating, .rc-feedback-input, .rc-submit-rating-btn').hide();
					$('.rc-rating-thankyou').fadeIn();
				});
			})
			.on('click', '.rc-attachment-image a', function (e) {
				e.preventDefault();
				var img = $(this).find('img');
				openLightbox($(this).attr('href'), img.attr('alt'));
			})
			.on('click', '.rc-lightbox, .rc-lightbox-close', function (e) {
				if (e.target === this) {
					closeLightbox();
				}
			});

		$(document).on('keydown', function (e) {
			if (e.key === 'Escape') {
				closeLightbox();
			}
		});

		$(document).on('visibilitychange', function () {
			if (!document.hidden) {
				resetTitle();
				if (state.currentTab === 'thread') {
					schedulePoll(0);
				}
			}
		});
	});
}(jQuery));
