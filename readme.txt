=== Anderson Appointment Booking ===
Contributors: cptnope
Tags: appointment, booking, google calendar, scheduling, calendar
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Let visitors book appointments with you. Availability comes live from your Google Calendar and every booking is added to it.

== Description ==

Anderson Appointment Booking puts a booking calendar on your site that knows when you're actually free. It reads free/busy times from your Google Calendar, offers only open slots within the hours you set, and adds each booking to your calendar — with a Google Meet link and a guest invite if you like.

* Live availability from one or more Google calendars
* Weekly hours with breaks, appointment length, buffers, minimum notice, booking window and daily cap
* Optional Google Meet link and calendar invite for the visitor
* Slot locking and a live re-check at booking time to prevent double bookings
* Confirmation email with a self-service cancel link; owner notifications
* Times shown in the visitor's own timezone
* Block and shortcode: `[anderson_booking]`
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

= Does it work with page caching? =

Yes. Times are loaded through the REST API when the page opens, so cached pages still show current availability.

== Changelog ==

= 1.0.0 =
* First release.
