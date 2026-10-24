# Anderson Appointment Booking

A WordPress plugin that lets visitors book appointments with you. Open times are read live from your Google Calendar, and every booking is added to it — with a Google Meet link and a calendar invite for the guest if you want.

- **Live availability** – reads free/busy from one or more Google calendars, so anything already on your calendar is never offered.
- **Your hours, your rules** – weekly hours with breaks, appointment length, start-time spacing, buffers before/after, minimum notice, how far ahead, and a daily cap.
- **Bookings go straight to Google** – event with the visitor's details, optional Meet link, optional guest invite.
- **No double bookings** – the slot is locked and re-checked against Google at the moment of booking.
- **Emails + self-service cancel** – confirmation to the visitor with a cancel link, notice to you; cancelling removes the Google event.
- **Shows times in the visitor's timezone**, works on mobile, inherits your theme's fonts, accent color is configurable.
- **Block + shortcode** – "Appointment Booking" block, or `[anderson_booking heading="Book a call"]`.
- **Private by design** – only the narrowest Calendar scopes; client secret and refresh token are encrypted at rest; no third-party services besides Google; personal-data export/erase hooks.
- **No build step, no Composer** – plain PHP and JavaScript.

## Requirements

WordPress 6.2+, PHP 7.4+ (OpenSSL recommended), a Google account.

## Setup

1. Install and activate the plugin, then go to **Appointments → Settings**.
2. In [Google Cloud Console](https://console.cloud.google.com/apis/library/calendar-json.googleapis.com), create or pick a project and **enable the Google Calendar API**.
3. Open **Google Auth Platform** (OAuth consent screen), choose **External**, and fill in the app name and your email.
4. Under **Audience**, click **Publish app** so it is *In production*.
   Apps left in *Testing* lose access after 7 days and booking stops. Verification isn't needed to use it with your own account — you'll see an "unverified app" screen once while connecting.
5. Under **Clients**, create an OAuth client of type **Web application** and add the redirect URI shown on the settings page (it looks like `https://your-site/wp-admin/admin-post.php?action=aab_oauth`).
6. Paste the client ID and secret into the settings page, save, and click **Connect Google Calendar**.
7. Pick the calendar bookings go to and any extra calendars to check for conflicts, set your hours, and save.
8. Add the **Appointment Booking** block (or the shortcode) to a page.

You can keep credentials out of the database by defining them in `wp-config.php`:

```php
define( 'AAB_GOOGLE_CLIENT_ID', '…apps.googleusercontent.com' );
define( 'AAB_GOOGLE_CLIENT_SECRET', '…' );
```

## How it works

| Piece | Where |
| --- | --- |
| Google OAuth 2.0 + Calendar v3 client (WordPress HTTP API) | `includes/class-aab-google.php` |
| Slot calculation (weekly hours → candidates → minus busy + buffers) | `includes/class-aab-availability.php` |
| Bookings (private post type), lock + live re-check, emails, cancel | `includes/class-aab-bookings.php` |
| Public REST API: `GET aab/v1/availability`, `POST aab/v1/bookings` | `includes/class-aab-rest.php` |
| Shortcode, block, visitor cancel page | `includes/class-aab-frontend.php` |
| Settings screen and bookings list | `includes/class-aab-admin.php`, `includes/views/settings.php` |
| Widget | `assets/js/booking.js`, `assets/css/booking.css` |

Scopes requested: `calendar.events`, `calendar.freebusy`, `calendar.calendarlist.readonly`. Only busy/free blocks are read from conflict calendars — never event titles or details.

Busy times are cached for two minutes per range and the cache is cleared whenever a booking is made or cancelled. If Google can't be reached, no times are offered (it fails closed rather than risking a double booking).

Spam protection: honeypot field, a minimum time on the form, and per-IP rate limits (5 bookings/hour, 20 attempts/hour). Behind a reverse proxy, use the `aab_client_ip` filter to supply the real client IP.

### Hooks

- `do_action( 'aab_booking_confirmed', $booking )`
- `do_action( 'aab_booking_cancelled', $booking, $by )` — `$by` is `visitor` or `admin`
- `apply_filters( 'aab_client_ip', $ip )`

### Cancelling

Visitors use the link in their confirmation email (it shows a confirm button first, so mail-app link scanners can't cancel by accident). You can cancel from **Appointments → Bookings** (row action), or trash an upcoming booking; both delete the Google event and email the visitor.

## License

MIT © Jeremy Anderson
