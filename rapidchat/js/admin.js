/** RapidChat operator inbox. */
(function ($) {
	'use strict';

	var EMOJIS = ['👋', '😊', '👍', '❤️', '🔥', '🎉', '🙌', '💡', '✨', '🚀', '💯', '🙏', '😃', '🤔', '😍', '👏', '🎯', '⭐', '🤝', '😎', '💬', '📞', '📧', '💼'];

	var state = {
		conversationId: null,
		lastMessageId: 0,
		renderGen: 0,
		fingerprint: '',
		lastRenderedDate: '',
		pendingDeleteId: null,
		isTyping: false,
		typingTimer: null,
		inFlight: false,
		timer: null,
		soundEnabled: true,
		currentFilter: 'all',
		cannedResponses: [],
		pendingAttachment: null,
		pendingSeq: 0,
		retryQueue: {},
		drafts: {}
	};

	var LAST_CONVERSATION_KEY = 'rapidchat_admin_last_conversation';
	var SOUND_KEY = 'rapidchat_admin_sound';

	try {
		if (localStorage.getItem(SOUND_KEY) === '0') {
			state.soundEnabled = false;
		}
	} catch (e) {}

	function renderSoundButton() {
		var $btn = $('.rc-inbox-sound-btn');
		$btn.toggleClass('is-muted', !state.soundEnabled)
			.attr('aria-pressed', state.soundEnabled ? 'true' : 'false')
			.attr('title', $btn.data(state.soundEnabled ? 'title-on' : 'title-off') || '');
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
			osc.frequency.setValueAtTime(523.25, now);
			osc.frequency.exponentialRampToValueAtTime(783.99, now + 0.1);

			gain.gain.setValueAtTime(0.2, now);
			gain.gain.exponentialRampToValueAtTime(0.001, now + 0.4);

			osc.connect(gain);
			gain.connect(audioCtx.destination);

			osc.start(now);
			osc.stop(now + 0.4);
		} catch (e) {}
	}

	function notifyDesktop(title, body) {
		if (!('Notification' in window)) {
			return;
		}
		if (Notification.permission === 'granted') {
			try {
				new Notification(title, {
					body: body,
					icon: (rapidchat_ajax && rapidchat_ajax.ajax_url ? rapidchat_ajax.ajax_url.replace(/admin-ajax\.php.*$/, '') : '') + 'images/rapidchat-icon.png'
				});
			} catch (e) {}
		}
	}

	function requestNotificationPermission() {
		if ('Notification' in window && Notification.permission === 'default') {
			Notification.requestPermission();
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

	function settled(response) {
		return response && typeof response.readyState === 'number' ? response.responseJSON : response;
	}

	function isError(response) {
		return !response || (typeof response === 'object' && response.success === false);
	}

	function errorText(response, fallback) {
		if (response && typeof response === 'object' && response.data) {
			if (typeof response.data === 'string') {
				return response.data;
			}
			if (response.data.message) {
				return response.data.message;
			}
		}
		return fallback;
	}

	function notify(selector, message, ok) {
		$(selector).empty().append(
			$('<p>').css('color', ok ? 'green' : 'red').text(message)
		);
	}

	function messagesContainer() {
		return $('.chat-widget-conversation-tab .chat-widget-messages');
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
		return d.getFullYear() + '/' + ('0' + (d.getMonth() + 1)).slice(-2) + '/' + ('0' + d.getDate()).slice(-2);
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

		var lastStaff = container.find('.chat-widget-message[data-mine="1"], .chat-widget-message[data-staff="1"]').last();
		var lastId = lastStaff.length ? parseInt(lastStaff.attr('data-message-id'), 10) : 0;

		if (lastId > 0 && lastId <= readThrough) {
			var anchor = lastStaff.nextAll('.msg-time').first();
			(anchor.length ? anchor : lastStaff).after($('<p>', { 'class': 'rc-seen-receipt' }).text('Seen'));
		}
	}

	function ensureTypingElement(container) {
		var el = container.find('.chat-typing-indicator');
		if (!el.length) {
			el = $('<div>', { 'class': 'chat-typing-indicator alt' })
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

	function updateSneakPeek(peekText) {
		var $box = $('#rc-sneak-peek-box');
		var $text = $('#rc-sneak-text');
		if (!$box.length) {
			return;
		}

		if (peekText && peekText.trim().length > 0) {
			$text.text(peekText);
			$box.slideDown(150);
		} else {
			$box.slideUp(150);
		}
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
		el.style.height = Math.min(el.scrollHeight, 140) + 'px';
		el.style.overflowY = el.scrollHeight > 140 ? 'auto' : 'hidden';
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

		if (row.id && container.find('.chat-widget-message[data-message-id="' + row.id + '"]').length) {
			return;
		}

		container.find('.chat-typing-indicator').remove();

		if (row.date && row.date !== state.lastRenderedDate) {
			$('<p>', { 'class': 'date-center', 'data-date': row.date })
				.text(dateLabel(row.date))
				.appendTo(container);
			state.lastRenderedDate = row.date;
		}

		if (row.msg_type === 'note') {
			var noteBox = $('<div>', {
				'class': 'chat-widget-message rc-message-note',
				'data-message-id': row.id,
				'data-author': row.author || '',
				'data-ts': row.ts || 0
			});
			noteBox.append(
				$('<div>', { 'class': 'rc-note-header' })
					.html('🔒 <span>Internal Note (Staff only)</span> · <span class="rc-note-author">' + escHtml(row.author || 'Operator') + '</span> · <span class="rc-note-time">' + escHtml(row.time || '') + '</span>')
			);
			noteBox.append(
				$('<div>', { 'class': 'rc-note-body' }).html(linkify(row.msg, true))
			);
			container.append(noteBox);
			return;
		}

		if (row.msg_type === 'system') {
			var sysBox = $('<div>', {
				'class': 'chat-widget-message rc-message-system',
				'data-message-id': row.id
			}).append($('<span>', { 'class': 'rc-system-badge' }).text(row.msg));
			container.append(sysBox);
			return;
		}

		var alt = row.staff ? '' : ' alt';

		var last = container.children('.chat-widget-message').last();
		var extending = last.length
			&& !last.hasClass('rc-message-note')
			&& !last.hasClass('rc-message-system')
			&& !last.nextAll('.date-center').length
			&& (last.attr('data-author') || '') === (row.author || '')
			&& !!last.attr('data-staff') === !!row.staff
			&& Math.abs((row.ts || 0) - (parseInt(last.attr('data-ts'), 10) || 0)) <= 300;

		if (extending) {
			container.children('.msg-time').last().remove();
			last.removeClass('rc-group-end')
				.find('.message-initial').addClass('rc-initial-spacer').text('');
		}

		var bubble = $('<div>', {
			'class': 'chat-widget-message' + alt + (extending ? '' : ' rc-group-start') + ' rc-group-end',
			title: row.time
		})
			.attr('data-message-id', row.id)
			.attr('data-author', row.author || '')
			.attr('data-ts', row.ts || 0)
			.attr('data-staff', row.staff ? '1' : '');

		bubble.append($('<span>', { 'class': 'message-initial' }).text(initial(row.author)));

		var contentBox = $('<div>', { 'class': 'message-bubble-content' });
		if (row.msg) {
			contentBox.append($('<div>', { 'class': 'message-text' }).html(linkify(row.msg, row.staff)));
		}

		if (row.attachment_url) {
			if (row.attachment_type === 'image') {
				var imgWrap = $('<div>', { 'class': 'rc-attachment-image' });
				$('<a>', { href: row.attachment_url, target: '_blank', rel: 'noopener noreferrer' })
					.append($('<img>', { src: row.attachment_url, alt: row.attachment_name || 'Image' }))
					.appendTo(imgWrap);
				contentBox.append(imgWrap);
			} else {
				var fileWrap = $('<div>', { 'class': 'rc-attachment-file' });
				var fileLink = $('<a>', { href: row.attachment_url, target: '_blank', rel: 'noopener noreferrer', 'class': 'rc-file-link' })
					.append($('<span>').text('📄 ' + (row.attachment_name || 'Download File')));
				if (row.attachment_size) {
					fileLink.append($('<span>', { 'class': 'rc-file-size' }).text(formatFileSize(row.attachment_size)));
				}
				fileLink.appendTo(fileWrap);
				contentBox.append(fileWrap);
			}
		}

		bubble.append(contentBox);
		container.append(bubble);

		var meta = row.time + (row.author ? ' · ' + row.author : '');
		$('<p>', { 'class': 'msg-time' + alt }).text(meta).appendTo(container);
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
			msg_type: 'message',
			staff: true,
			author: $('#admin-name').text() || '',
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
		var author = bubble.attr('data-author') || '';
		bubble.next('.msg-time').text('Sent' + (author ? ' · ' + author : ''));
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

		post('rapidchat_admin_post_message', {
			id: conversationId,
			msg: text,
			msg_type: 'message',
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
					markBubbleFailed(clientId, errorText(response, 'Error submitting message.'));
				}
			})
			.fail(function (xhr) {
				markBubbleFailed(clientId, errorText(xhr && xhr.responseJSON, 'Unable to submit message.'));
			});
	}

	function appendPendingNote(clientId, text) {
		appendMessage({
			id: clientId,
			msg: text,
			msg_type: 'note',
			author: $('#admin-name').text() || '',
			ts: Math.floor(Date.now() / 1000),
			time: 'Sending…',
			date: todayStamp()
		});
		messagesContainer().find('[data-message-id="' + clientId + '"]').attr('data-pending', '1');
		scrollToBottom(messagesContainer());
	}

	function markNoteSent(clientId) {
		var bubble = messagesContainer().find('[data-message-id="' + clientId + '"]');
		bubble.removeAttr('data-pending').attr('data-sent', '1');
		bubble.find('.rc-note-time').text('Sent');
	}

	function markNoteFailed(clientId, message) {
		var bubble = messagesContainer().find('[data-message-id="' + clientId + '"]');
		bubble.removeAttr('data-pending').attr('data-failed', '1');
		bubble.find('.rc-note-time').text('Failed');
		bubble.find('.rc-note-body').after(
			$('<div>', { 'class': 'rc-note-status' }).html(
				escHtml(message || 'Failed to send') + ' · <button type="button" class="rc-retry-btn" data-pending-id="' + clientId + '">Retry</button>'
			)
		);
	}

	function submitNote(form, text, att) {
		var $form = $(form);
		var conversationId = $form.find('input[name="id"]').val();

		if (!conversationId || (!text && !att)) {
			return;
		}

		var clientId = 'pending-' + (++state.pendingSeq);
		state.retryQueue[clientId] = { form: form, text: text, att: att, note: true };
		appendPendingNote(clientId, text);

		post('rapidchat_admin_post_message', {
			id: conversationId,
			msg: text,
			msg_type: 'note',
			attachment_url: att ? att.url : '',
			attachment_type: att ? att.type : '',
			attachment_name: att ? att.name : '',
			attachment_size: att ? att.size : 0
		})
			.done(function (response) {
				if (response && response.success) {
					delete state.retryQueue[clientId];
					markNoteSent(clientId);
					schedulePoll(0);
				} else {
					markNoteFailed(clientId, errorText(response, 'Error submitting note.'));
				}
			})
			.fail(function (xhr) {
				markNoteFailed(clientId, errorText(xhr && xhr.responseJSON, 'Unable to submit note.'));
			});
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
				.appendTo('#rapidchat-admin');
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

	function loadConversations() {
		return post('rapidchat_admin_conversations_user', { filter: state.currentFilter }).done(function (response) {
			if (isError(response)) {
				return;
			}
			$('.chat-widget-conversations-tab').html(response);
			if (state.conversationId) {
				$('.chat-widget-conversation[data-id="' + state.conversationId + '"]').addClass('active');
			}
			state.fingerprint = '';
			filterUsers();
			schedulePoll(0);
		});
	}

	function loadSmartSuggestions(convoId) {
		if (!convoId) {
			return;
		}
		post('rapidchat_get_smart_suggestions', { conversation_id: convoId }).done(function (response) {
			if (response && response.success && response.data && response.data.suggestions) {
				var $container = $('#rc-suggestion-chips').empty();
				$.each(response.data.suggestions, function (_, s) {
					var chip = $('<button>', {
						type: 'button',
						'class': 'rc-suggestion-chip',
						'data-text': s.text,
						title: s.text
					}).text(s.title);
					$container.append(chip);
				});
				if (response.data.suggestions.length > 0) {
					$('#rc-smart-suggestions').slideDown(150);
				}
			}
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

	(function revealActiveNavItem() {
		var nav = document.getElementById('rc-sidebar-nav');
		var active = nav && nav.querySelector('.rc-nav-item.is-active');
		if (active && nav.scrollWidth > nav.clientWidth) {
			nav.scrollLeft = active.offsetLeft - (nav.clientWidth - active.offsetWidth) / 2;
		}
	}());

	(function initSectionSpy() {
		var nav = document.querySelector('#rc-sidebar-nav[data-spy]');
		if (!nav || !('IntersectionObserver' in window)) {
			return;
		}

		var links = nav.querySelectorAll('.rc-nav-item');
		function activate(id) {
			links.forEach(function (link) {
				var on = link.getAttribute('data-section') === id;
				link.classList.toggle('is-active', on);
				if (on) {
					link.setAttribute('aria-current', 'true');
				} else {
					link.removeAttribute('aria-current');
				}
			});
		}

		var observer = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (entry.isIntersecting) {
					activate(entry.target.id);
				}
			});
		}, { rootMargin: '-120px 0px -60% 0px' });

		links.forEach(function (link) {
			var section = document.getElementById(link.getAttribute('data-section'));
			if (section) {
				observer.observe(section);
			}
		});
	}());

	function resumeLastConversation() {
		var savedId;

		var linked = parseInt(new URLSearchParams(window.location.search).get('conversation'), 10);
		if (linked > 0) {
			openConversation(linked);
			return;
		}

		try {
			savedId = localStorage.getItem(LAST_CONVERSATION_KEY);
		} catch (e) {
			return;
		}
		if (savedId && $('.chat-widget-conversation[data-id="' + savedId + '"]').length) {
			openConversation(parseInt(savedId, 10));
		}
	}

	function openConversation(id, force) {
		if (state.conversationId === id && !force) {
			return;
		}

		saveComposerDraft(state.conversationId);
		state.pendingAttachment = null;

		state.conversationId = id;
		state.lastMessageId = 0;
		state.renderGen++;
		state.lastRenderedDate = '';
		try {
			localStorage.setItem(LAST_CONVERSATION_KEY, String(id));
		} catch (e) {}

		$('.chat-widget-conversation').removeClass('active');
		$('.chat-widget-conversation[data-id="' + id + '"]').addClass('active');

		return post('rapidchat_conversation_admin', { conversation_id: id }).always(function (response) {
			response = settled(response);
			if (isError(response)) {
				notify('#response-message', errorText(response, 'Unable to open conversation.'), false);
				return;
			}

			$('.chat-widget-conversation-tab').html(response);
			restoreComposerDraft(id);
			syncCursorFromDom();
			updateSeenReceipt(messagesContainer().data('read-through'));
			scrollToBottom(messagesContainer());
			initEmojiPicker();
			$(document).trigger('rapidchat:conversation-rendered', [id]);
			loadSmartSuggestions(id);
			applyDetailsState();
			schedulePoll(0);
		});
	}

	function applyDetailsState() {
		var saved = null;
		try {
			saved = localStorage.getItem('rapidchat_details_open');
		} catch (e) {}
		var isOpen = saved === null ? window.innerWidth > 1080 : saved !== '0';
		if (!isOpen) {
			$('.chat-widget-right-profile').addClass('rc-profile-hidden');
			$('.chat-widget-left-chat').addClass('rc-expanded-chat');
			$('.rc-toggle-details-btn').removeClass('active');
		} else {
			$('.chat-widget-right-profile').removeClass('rc-profile-hidden');
			$('.chat-widget-left-chat').removeClass('rc-expanded-chat');
			$('.rc-toggle-details-btn').addClass('active');
		}
	}

	function loadCannedResponses() {
		$.ajax({
			url: rapidchat_ajax.rest_url + 'operator/canned-responses',
			method: 'GET',
			beforeSend: function (xhr) {
				xhr.setRequestHeader('X-WP-Nonce', rapidchat_ajax.rest_nonce);
			}
		}).done(function (response) {
			if (response && response.items) {
				state.cannedResponses = response.items;
			}
		});
	}

	function sendMessage(form) {
		var $form = $(form);
		var input = $form.find('[name="msg"]');
		var conversationId = $form.find('input[name="id"]').val();
		var msgType = $form.find('input[name="msg_type"]').val() || 'message';
		var text = $.trim(input.val());
		var att = state.pendingAttachment;

		if (!conversationId || (!text && !att)) {
			return;
		}

		state.isTyping = false;
		resetComposer(input[0]);
		clearAttachmentPreview();
		hideCannedAutocomplete();
		input.trigger('focus');

		if (msgType === 'note') {
			submitNote(form, text, att);
		} else {
			submitMessage(form, text, att);
		}
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
		}).fail(function () {
			previewBox.hide();
			window.alert('Upload error. Please try again.');
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

	function handleCannedInput(inputVal) {
		if (inputVal.indexOf('/') !== 0) {
			hideCannedAutocomplete();
			return;
		}

		var query = inputVal.slice(1).toLowerCase();
		var matches = $.grep(state.cannedResponses, function (item) {
			return item.shortcut.toLowerCase().indexOf(query) !== -1 || item.title.toLowerCase().indexOf(query) !== -1;
		});

		if (!matches.length) {
			hideCannedAutocomplete();
			return;
		}

		var menu = $('#rc-canned-autocomplete').empty();
		$.each(matches, function (_, item) {
			var row = $('<div>', { 'class': 'rc-canned-item' })
				.attr('data-content', item.content)
				.append($('<div>', { 'class': 'rc-canned-shortcut' }).text('/' + item.shortcut + ' - ' + item.title))
				.append($('<div>', { 'class': 'rc-canned-preview' }).text(item.content));
			menu.append(row);
		});

		menu.show();
	}

	function hideCannedAutocomplete() {
		$('#rc-canned-autocomplete').empty().hide();
	}

	function schedulePoll(delay) {
		window.clearTimeout(state.timer);
		state.timer = window.setTimeout(poll, delay);
	}

	function poll() {
		if (state.inFlight || document.hidden) {
			return;
		}

		state.inFlight = true;
		var gen = state.renderGen;

		post('rapidchat_admin_poll', {
			conversation_id: state.conversationId || 0,
			since_id: state.lastMessageId,
			fingerprint: state.fingerprint,
			typing: state.isTyping ? 1 : 0,
			filter: state.currentFilter || 'all'
		}).done(function (response) {
			if (!response || !response.success) {
				return;
			}

			var data = response.data;
			var container = messagesContainer();
			var pinned = isPinnedToBottom(container);

			if (data.list !== null && typeof data.list !== 'undefined') {
				$('.chat-widget-conversations-tab').html(data.list);
				if (state.conversationId) {
					$('.chat-widget-conversation[data-id="' + state.conversationId + '"]').addClass('active');
				}
				filterUsers();
			}
			state.fingerprint = data.fingerprint || '';

			if (gen !== state.renderGen) {
				schedulePoll(0);
				return;
			}

			if (data.messages && data.messages.length) {
				messagesContainer().find('[data-sent="1"]').each(function () {
					$(this).next('.msg-time').remove();
					$(this).remove();
				});

				var hasIncoming = false;
				$.each(data.messages, function (_, row) {
					appendMessage(row);
					if (!row.staff && row.msg_type === 'message') {
						hasIncoming = true;
						if (document.hidden) {
							notifyDesktop('RapidChat: ' + (row.author || 'Visitor'), row.msg);
						}
					}
				});
				state.lastMessageId = Math.max(state.lastMessageId, data.last_id || 0);

				if (hasIncoming) {
					playChime();
				}

				if (pinned) {
					scrollToBottom(messagesContainer());
				}
			}

			setTypingDisplay(!!data.typing, data.typing_user || '');
			updateSneakPeek(data.sneak_peek || '');
			updateSeenReceipt(data.read_through);

			schedulePoll(data.next_delay || 15000);
		}).fail(function () {
			schedulePoll(30000);
		}).always(function () {
			state.inFlight = false;
		});
	}

	function filterUsers() {
		var filter = $.trim($('#user-search').val() || '').toLowerCase();
		var headings = '.chat-widget-users, .chat-widget-users-unassigned, .chat-widget-users-unread';
		var matches = 0;
		var unreadTotal = 0;

		$('.chat-widget-conversation').each(function () {
			var text = $(this).text().toLowerCase();
			var tags = $(this).attr('data-tags') || '';
			var unreadCount = parseInt($(this).attr('data-unread'), 10) || 0;
			var isUnread = $(this).hasClass('new-message') || unreadCount > 0;
			var isClosed = $(this).attr('data-is-closed') === '1' || $(this).hasClass('rc-row-closed');
			var isMine = $(this).attr('data-mine') === '1';
			var isQueued = $(this).attr('data-queued') === '1';

			if (isUnread) {
				unreadTotal++;
			}

			var searchHit = !filter || text.indexOf(filter) !== -1 || tags.indexOf(filter) !== -1;
			var tabHit = true;

			if (state.currentFilter === 'unread') {
				tabHit = isUnread;
			} else if (state.currentFilter === 'mine') {
				tabHit = isMine && !isClosed;
			} else if (state.currentFilter === 'queued') {
				tabHit = isQueued && !isClosed;
			} else if (state.currentFilter === 'closed') {
				tabHit = isClosed;
			}

			var hit = searchHit && tabHit;
			this.style.display = hit ? '' : 'none';
			matches += hit ? 1 : 0;
		});

		var $badge = $('#rc-unread-tab-count');
		if (unreadTotal > 0) {
			$badge.text(unreadTotal).show();
		} else {
			$badge.hide();
		}

		$(headings).each(function () {
			var visible = $(this).nextUntil(headings, '.chat-widget-conversation')
				.filter(function () { return this.style.display !== 'none'; });
			this.style.display = visible.length ? '' : 'none';
		});

		$('.rc-inbox-no-results').remove();
		if (!matches) {
			var emptyMsg = 'No conversations match your filter.';
			if (filter) {
				emptyMsg = 'No conversations match “' + $('#user-search').val() + '”.';
			} else if (state.currentFilter === 'unread') {
				emptyMsg = 'No unread conversations.';
			} else if (state.currentFilter === 'mine') {
				emptyMsg = 'No active conversations assigned to you.';
			} else if (state.currentFilter === 'queued') {
				emptyMsg = 'No queued conversations waiting.';
			} else if (state.currentFilter === 'closed') {
				emptyMsg = 'No closed conversations.';
			}
			$('<div>', { 'class': 'rc-inbox-no-results rc-no-conversations' })
				.append($('<p>').text(emptyMsg))
				.appendTo('.chat-widget-scrollable');
		}
	}

	function getCurrentTags() {
		var tags = [];
		$('#rc-tags-list .rc-tag-pill').each(function () {
			var t = $(this).attr('data-tag');
			if (t) {
				tags.push(t);
			}
		});
		return tags;
	}

	function saveTags(tags) {
		if (!state.conversationId) {
			return;
		}
		post('rapidchat_update_tags', {
			conversation_id: state.conversationId,
			tags: tags
		}).done(function (response) {
			if (response && response.success) {
				loadConversations();
			}
		});
	}

	function addTagToDOM(tagName) {
		tagName = $.trim(tagName);
		if (!tagName) {
			return;
		}
		var current = getCurrentTags();
		if (current.indexOf(tagName) !== -1) {
			return;
		}
		current.push(tagName);

		var pill = $('<span>', { 'class': 'rc-tag-pill', 'data-tag': tagName })
			.text(tagName)
			.append($('<button>', { type: 'button', 'class': 'rc-tag-remove', 'aria-label': 'Remove' }).html('&times;'));
		$('#rc-tags-list').append(pill);
		$('#rc-new-tag-input').val('');

		saveTags(current);
	}

	window.RapidChatAdmin = {
		post: post,
		settled: settled,
		errorText: errorText,
		notify: notify,
		state: state,
		openConversation: openConversation,
		loadConversations: loadConversations,
		chatAction: chatAction
	};

	$(function () {
		if (!$('#rapidchat-admin').length) {
			return;
		}

		requestNotificationPermission();
		loadCannedResponses();

		post('rapidchat_admin_login_user').done(function (response) {
			if (!response || !response.success) {
				$('.msg').text(errorText(response, 'Unable to open inbox.'));
				return;
			}

			$('#admin-name').text(response.data.full_name);
			$('#role-name').text(response.data.role);
			$('#admin-avatar').text(initial(response.data.full_name));
			loadConversations().done(resumeLastConversation);
			renderSoundButton();
		}).fail(function () {
			$('.msg').text('Error connecting to the server. Please try again.');
		});

		$(document)
			.on('click', '.chat-widget-conversation', function (e) {
				e.preventDefault();
				openConversation($(this).data('id'));
			})
			.on('click', '.rc-inbox-sound-btn', function () {
				state.soundEnabled = !state.soundEnabled;
				try {
					localStorage.setItem(SOUND_KEY, state.soundEnabled ? '1' : '0');
				} catch (e) {}
				renderSoundButton();
			})
			.on('click', '.rc-filter-tab', function (e) {
				e.preventDefault();
				$('.rc-filter-tab').removeClass('active');
				$(this).addClass('active');
				state.currentFilter = $(this).data('filter') || 'all';
				filterUsers();
				state.fingerprint = '';
				loadConversations();
			})
			.on('click', '.rc-composer-tab', function (e) {
				e.preventDefault();
				$('.rc-composer-tab').removeClass('active');
				$(this).addClass('active');
				var mode = $(this).data('mode');
				$('#rc-composer-msg-type').val(mode);
				var input = $('#rc-composer-input');
				if (mode === 'note') {
					input.attr('placeholder', 'Type a private internal note (visible to staff only)...');
					$('.rc-admin-composer-form .send-button').addClass('rc-btn-note-send');
				} else {
					input.attr('placeholder', 'Type a reply (type / for canned responses)...');
					$('.rc-admin-composer-form .send-button').removeClass('rc-btn-note-send');
				}
				input.trigger('focus');
			})
			.on('click', '.rc-suggestion-chip', function (e) {
				e.preventDefault();
				var input = $('#rc-composer-input');
				input.val($(this).data('text')).trigger('focus');
				autogrow(input[0]);
			})
			.on('click', '#rc-add-tag-btn', function (e) {
				e.preventDefault();
				addTagToDOM($('#rc-new-tag-input').val());
			})
			.on('keydown', '#rc-new-tag-input', function (e) {
				if (e.key === 'Enter') {
					e.preventDefault();
					addTagToDOM($(this).val());
				}
			})
			.on('click', '.rc-quick-tag-btn', function (e) {
				e.preventDefault();
				var tag = $(this).data('tag');
				addTagToDOM(tag);
				$(this).remove();
			})
			.on('click', '.rc-tag-remove', function (e) {
				e.preventDefault();
				$(this).closest('.rc-tag-pill').remove();
				saveTags(getCurrentTags());
			})
			.on('click', '.rc-toggle-details-btn', function (e) {
				e.preventDefault();
				var $profile = $('.chat-widget-right-profile');
				var $leftChat = $('.chat-widget-left-chat');
				var $btn = $(this);
				var willBeOpen = $profile.hasClass('rc-profile-hidden');

				if (willBeOpen) {
					$profile.removeClass('rc-profile-hidden');
					$leftChat.removeClass('rc-expanded-chat');
					$btn.addClass('active');
					localStorage.setItem('rapidchat_details_open', '1');
				} else {
					$profile.addClass('rc-profile-hidden');
					$leftChat.addClass('rc-expanded-chat');
					$btn.removeClass('active');
					localStorage.setItem('rapidchat_details_open', '0');
				}
			})
			.on('click', '.rc-profile-close-btn', function (e) {
				e.preventDefault();
				$('.chat-widget-right-profile').addClass('rc-profile-hidden');
				$('.chat-widget-left-chat').addClass('rc-expanded-chat');
				$('.rc-toggle-details-btn').removeClass('active');
				localStorage.setItem('rapidchat_details_open', '0');
			})
			.on('click', '.rc-claim-self-btn', function (e) {
				e.preventDefault();
				$(this).prop('disabled', true);
				chatAction('rapidchat_claim_conversation', $(this).data('conversation-id'), {}, 'Could not accept this chat.');
			})
			.on('input', '.chat-widget-input-message [name="msg"]', function () {
				autogrow(this);
				var val = $(this).val();
				handleCannedInput(val);

				state.isTyping = true;
				window.clearTimeout(state.typingTimer);
				state.typingTimer = window.setTimeout(function () {
					state.isTyping = false;
				}, 3000);
			})
			.on('keydown', '.chat-widget-input-message [name="msg"]', function (e) {
				if (e.key === 'Enter' && !e.shiftKey && !TOUCH && !$('#rc-canned-autocomplete:visible').length) {
					e.preventDefault();
					sendMessage($(this).closest('form')[0]);
				}
			})
			.on('click', '.rc-canned-item', function () {
				var input = $('.chat-widget-input-message [name="msg"]');
				input.val($(this).data('content')).trigger('focus');
				autogrow(input[0]);
				hideCannedAutocomplete();
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
				if (entry.note) {
					submitNote(entry.form, entry.text, entry.att);
				} else {
					submitMessage(entry.form, entry.text, entry.att);
				}
			})
			.on('keyup', '#user-search', filterUsers)
			.on('click', '.rc-email-transcript-btn', function () {
				var id = $(this).data('id');
				post('rapidchat_admin_send_transcript', { id: id }).always(function (response) {
					response = settled(response);
					window.alert(response && response.success ? 'Transcript emailed to visitor!' : 'Failed to send transcript.');
				});
			})
			.on('click', '.rc-close-conversation-btn', function () {
				var id = $(this).data('id');
				if (window.confirm('Are you sure you want to end this chat conversation?')) {
					post('rapidchat_close_conversation', { conversation_id: id }).always(function (response) {
						response = settled(response);
						if (response && response.success) {
							openConversation(id, true);
							loadConversations();
						} else {
							window.alert(errorText(response, 'Failed to close conversation.'));
						}
					});
				}
			})
			.on('click', '.delete-chat-btn', function () {
				state.pendingDeleteId = $(this).data('id');
				$('#delete-confirm-modal').prop('hidden', false).fadeIn();
			})
			.on('click', '#confirm-delete-no', function () {
				state.pendingDeleteId = null;
				$('#delete-confirm-modal').fadeOut();
			})
			.on('click', '#confirm-delete-yes', function () {
				if (!state.pendingDeleteId) {
					return;
				}

				post('rapidchat_delete_chat', { conversation_id: state.pendingDeleteId })
					.always(function (response) {
						response = settled(response);
						$('#delete-confirm-modal').fadeOut();

						if (response && response.success) {
							state.pendingDeleteId = null;
							state.conversationId = null;
							state.lastMessageId = 0;
							$('.chat-widget-conversation-tab').empty();
							loadConversations();
						} else {
							notify('#response-message-delete', errorText(response, 'Failed to delete chat.'), false);
						}
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
				schedulePoll(0);
			}
		});
	});

	function chatAction(action, conversationId, extra, fallback) {
		if (!conversationId) {
			return;
		}

		function settle(response) {
			if (!response || !response.success) {
				window.alert(errorText(response, fallback));
			}
			openConversation(conversationId, true);
			loadConversations();
		}

		post(action, $.extend({ conversation_id: conversationId }, extra))
			.done(settle)
			.fail(function (xhr) {
				settle(xhr && xhr.responseJSON);
			});
	}

	$(document).on('click', '.rc-confirm', function () {
		return window.confirm($(this).data('confirm'));
	});
}(jQuery));
