=== RapidChat ===
Contributors: softaculous
Tags: live chat, chat, customer support, helpdesk, messaging
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fast, self-hosted live chat for WordPress: a visitor widget, a live inbox, canned replies, transcripts and privacy tools.

== Description ==

RapidChat runs entirely on your WordPress server: conversations, visitors and settings stay in your own database.

**For visitors**

* A lightweight chat widget, as a direct chat or a hub with a home screen.
* Pre-chat form with an optional message field.
* Offline messages, CSAT rating and emailed transcripts.

**For your team**

* Live Inbox with queued, mine, unread and closed views, private staff notes, tags, canned responses (type `/`) and smart suggestions.
* Administrators answer chats, and so can any user whose role has the `rapidchat_operate` capability (`rapidchat_manage` for full access). Grant it with any role editor plugin.

A separate Pro add-on with extra team features is available at softaculous.com. Everything described on this page is included in this free plugin.

**Lightweight and secure**

* Adaptive polling (no WebSocket server needed), loaded only where the widget is shown.
* Hashed session tokens, nonces on every action, rate limiting, prepared SQL and escaped output.

== Installation ==

1. Upload the `rapidchat` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu.
3. Open **RapidChat → Settings** to customize the widget and choose which administrator answers chats.
4. Answer chats from **RapidChat → Live Inbox**. The widget appears on every page by default (configurable).

== Frequently Asked Questions ==

= Who can answer chats? =

Any administrator, plus any number of other users: give their role the `rapidchat_operate` capability (or `rapidchat_manage` for full access) with a role editor plugin, and they can answer chats from the Live Inbox. There is no limit on the number of agents.

= Can I show the chat widget on specific pages only? =

Yes. Turn off "Show on every page" under **Settings → Widget Customizer** and add the `[rapid_chat]` shortcode where you want the widget.

= How does real-time messaging work without WebSockets? =

RapidChat uses adaptive short-polling: every 2 seconds while a conversation is active, backing off to 30 seconds as it goes idle.

= Is RapidChat free? =

Yes. Every feature described here is included, with no limit on agents, chats or visitors.

== Privacy ==

Chats, visitor names, email addresses, IP addresses and uploaded files are stored in your own database and uploads folder. RapidChat adds suggested text to **Settings → Privacy**, and works with **Tools → Export Personal Data** and **Tools → Erase Personal Data**: a visitor is found by the email address they entered, and erasing removes their conversations, uploaded files and visitor record. The widget sets one cookie, `rapidchat_token`, to keep a visitor in their conversation for up to 30 days.

== External services ==

RapidChat contacts this service:

* **ipbase.com** (off by default) -- only once an administrator turns on **Settings → General & Security → Visitor geolocation**, looks up each visitor's country from their IP address in a background task, for the country flag. Sent: the visitor's IP address. [Terms](https://ipbase.com/terms-of-service/) · [Privacy policy](https://ipbase.com/privacy-policy/)

== Extending RapidChat ==

Developers can extend RapidChat with actions and filters, for example `rapidchat_loaded`, `rapidchat_settings_tabs`, `rapidchat_widget_script_data` and `rapidchat_smart_suggestions`. See the documentation at https://www.softaculous.com/docs/rapidchat/ for the full list.

== Screenshots ==

1. Chat widget on a WordPress site.
2. Live Inbox with the conversation list, chat thread and visitor details.
3. Settings.

== Changelog ==

= 1.0.0 =
* Initial release.
