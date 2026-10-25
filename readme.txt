=== Anderson Appointment Booking ===
Contributors: cptnope
Tags: appointment, booking, google calendar, scheduling, calendar
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.3.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Let visitors book appointments with you. Availability comes live from your Google Calendar and every booking is added to it.

== Description ==

Anderson Appointment Booking puts a booking calendar on your site that knows when you're actually free. It reads free/busy times from your Google Calendar, offers only open slots within the hours you set, and adds each booking to your calendar — with a Google Meet link and a guest invite if you like.

* Live availability from one or more Google calendars
* Appointment types, each with its own length, hours, location, video call and booking questions
* Custom booking questions: short answer, long answer, dropdown, checkbox
* Visitors can reschedule or cancel from a link in every email, with a cut-off you choose
* Weekly hours with breaks, buffers, minimum notice, booking window and daily cap
* Optional Google Meet link and calendar invite for the visitor
* Atomic slot locking and a live re-check at booking time to prevent double bookings
* Follows changes made in Google Calendar: deleted or moved appointments update on the site
* Confirmation (with calendar file), reminder and change emails; owner notifications
* Days off, per-person booking cap, optional or required phone number
* Site Health checks, an email alert if Google access breaks, CSV export, automatic record clean-up
* Times shown in the visitor's own timezone
* Matches your theme automatically (block and classic themes): fonts, colors, buttons, fields, corners
* Appearance settings with a live preview inside your theme, per-block overrides, shortcode attributes and CSS variables
* Block and shortcode: `[anderson_booking]`, or one type with `[anderson_booking type="consult"]`
* Narrow Google permissions; credentials encrypted at rest
* No build tools, no Composer, no extra services

Built by [Jeremy Anderson](https://jeremyanderson.tech).

= External services =

This plugin connects to Google's OAuth and Google Calendar APIs, using the Google Cloud credentials you create, in order to read your free/busy times, list your calendars, and create or delete appointment events.

* Data sent when a visitor views the booking calendar: the time range being viewed and the IDs of the calendars to check (from your server to Google).
* Data sent when a visitor books: the appointment time and the name, email address and notes the visitor entered, which become a Google Calendar event (and, if enabled, a guest invite emailed by Google).
* Google's terms: https://policies.google.com/terms — privacy policy: https://policies.google.com/privacy

== Installation ==

1. Install and activate the plugin, then open Appointments → Settings.
2. Follow the on-screen steps to create a Google OAuth client (Web application) and paste in the client ID and secret.
3. Publish your OAuth app to "In production" in Google Cloud (apps in Testing lose access after 7 days).
4. Click Connect Google Calendar, choose your calendars and hours, and save.
5. Add the Appointment Booking block or the `[anderson_booking]` shortcode to a page.

== Frequently Asked Questions ==

= Can visitors see my events? =

No. Only free/busy blocks are read from conflict calendars, and visitors only see open start times.

= What happens if Google is unreachable? =

No times are offered until it is reachable again, so nobody can book over an event the plugin couldn't see.

= Why did booking stop after a week? =

Your Google OAuth app is in Testing mode. Publish it to In production in Google Cloud and connect again.

= Can I make it match my site? =

By default it does: it uses your theme's fonts, button and field styles, colors and corner radius. Fine-tune it under Appointments → Settings → Appearance (with a live preview), per block in the editor, with shortcode attributes, or with CSS variables such as `--aab-accent` and `--aab-radius`.

= My vacation is in Google Calendar but people can still book =

All-day events are marked "Free" in Google Calendar by default, so they don't block times. Set the event to "Busy", or add the dates under Appointments → Settings → Days off.

= My host disables WP-Cron. Does anything break? =

Booking works, but reminders and syncing with changes made in Google Calendar need the hourly task. Add a server cron job that requests wp-cron.php every few minutes. Tools → Site Health tells you if the task isn't running.

= Can visitors reschedule? =

Yes. Every email links to a page where they can pick a new time (the same calendar, with their current slot treated as free) or cancel. The Google Calendar event is moved in place, so the Meet link stays the same. You can stop online changes within N hours of the appointment and limit how many times one booking can move.

= Can I offer different kinds of appointments? =

Yes. Under Appointments → Appointment types, add as many as you like, each with its own length, start-time spacing, hours, location, video setting and questions. Visitors pick a type first. Use type="…" in the shortcode, or the block setting, to show just one.

= Does it work with page caching? =

Yes. Times are loaded through the REST API when the page opens, so cached pages still show current availability.

== Changelog ==

= 1.3.0 =
* New: Appointment types with their own length, start-time spacing, hours, location, video-call setting and questions. Visitors pick a type, then a time.
* New: Custom booking questions (short answer, long answer, dropdown, checkbox), included in the Google event, emails, booking record, CSV and privacy export.
* New: Visitors can reschedule from a page linked in every email; the Google event moves in place. Settings for a change cut-off and a reschedule limit.
* New: Shortcode type="…" and a block setting to show one appointment type.
* Fix: Appointments booked through the plugin now keep their free time before and after, so nothing can be booked right up against them.
* Upgrading creates one appointment type from your existing settings. Old cancel links keep working.

= 1.2.0 =
* New: Appointments deleted or moved in Google Calendar are cancelled or updated on the site, and the visitor is emailed.
* New: Reminder emails, a calendar file attached when no Google invite is sent, and the cancel link in every email.
* New: Days off, a cap on upcoming bookings per person, and an optional or required phone number.
* New: Site Health checks, an email to the owner if Google access breaks, a problem log, "Check Google Calendar now", CSV export, automatic record clean-up.
* New: Classic themes' heading font is detected; arrow-key navigation in the calendar.
* Fix: The slot lock is now atomic, so simultaneous requests can never double-book.
* Fix: Rate limiting uses the real visitor IP behind proxies and caches such as Varnish or Cloudflare.
* Improved: Google requests retry on timeouts and rate limits without creating duplicate events.

= 1.1.0 =
* New: "Match my theme" styling for block and classic themes (colors, buttons, fields, corners, heading font).
* New: Appearance settings with a live preview rendered in your theme.
* New: Per-block style controls and live preview in the editor; block color, typography and spacing support.
* New: Shortcode style attributes, custom CSS, CSS variables and styling filters.
* The calendar adapts to the width of its container; the cancel page uses your theme's styles.

= 1.0.0 =
* First release.
