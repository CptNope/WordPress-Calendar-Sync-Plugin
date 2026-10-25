# Anderson Appointment Booking

A WordPress plugin that lets visitors book appointments with you. Open times are read live from your Google Calendar, and every booking is added to it — with a Google Meet link and a calendar invite for the guest if you want.

- **Live availability** – reads free/busy from one or more Google calendars, so anything already on your calendar is never offered.
- **Appointment types** – e.g. a 15-minute intro and a 60-minute on-site consult, each with its own length, start-time spacing, hours, location, video-call setting and booking questions. Visitors pick a type, then a time.
- **Custom booking questions** – short answer, long answer, dropdown or checkbox, required or optional, per type. Answers land in the Google event, your email, the booking record and the CSV.
- **Self-service rescheduling** – every email links to a page where the visitor can move or cancel the appointment, with a cut-off ("no changes within 24 hours") and an optional limit on how many times one booking can move.
- **Your hours, your rules** – weekly hours with breaks, buffers before/after, minimum notice, how far ahead, and a daily cap.
- **Bookings go straight to Google** – event with the visitor's details, optional Meet link, optional guest invite.
- **No double bookings** – an atomic database lock plus a live re-check against Google at the moment of booking.
- **Stays in step with Google Calendar** – delete or move an appointment in Google Calendar and the site follows within the hour (and emails the visitor).
- **Emails** – confirmation with a reschedule/cancel link (plus a calendar file when Google isn't sending an invite), reminder before the appointment, move/cancel notices, owner notifications.
- **Booking rules** – weekly hours with breaks, days off, buffers, notice, booking window, daily cap, a cap on upcoming bookings per person, optional or required phone number.
- **Shows times in the visitor's timezone** and adapts to its own width, so it fits pages, columns and sidebars.
- **Matches any theme** – fonts, colors, buttons, form fields, corners and heading font come from the active theme (block or classic), or style it yourself. See [Styling](#styling).
- **Block + shortcode** – "Appointment Booking" block with a live styled preview, or `[anderson_booking heading="Book a call"]`. Show a single type with `[anderson_booking type="consult"]` or the block's type setting.
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

## Appointment types, questions and rescheduling

**Appointments → Appointment types** lists what visitors can book. Each type has:

| Setting | Notes |
| --- | --- |
| Name, description | Shown on the type picker. |
| Length, start times every | E.g. 60 minutes, offered every 30 minutes. |
| Hours | Your usual hours (Settings) or the type's own weekly hours. Other appointments and Google events still block times across all types. |
| Location, video call | Overrides the defaults from Settings: an address for in-person types, or no Meet link. |
| Questions | Short answer, long answer, dropdown, checkbox; required or optional; reorderable. |
| Notes box | Show or hide "Anything I should know?". |
| Visible | Hide a type without deleting it. |

Buffers, minimum notice, booking window, days off and the daily cap are shared, because one calendar can only hold one appointment at a time. Appointments booked through the plugin keep their buffers on both sides.

The order in the list is the order visitors see. With one visible type the picker is skipped. `[anderson_booking type="ID"]` (or the block's **Appointment types** setting) shows one type only, so you can put the consult on your services page and the intro call on your contact page.

**Rescheduling.** Confirmation, reminder and change emails link to a page on your site (styled by your theme) where the visitor sees the appointment and can **Reschedule** or **Cancel**. Rescheduling shows the same calendar for that appointment's type, treats the appointment's current slot as free (so it can move 30 minutes later even though that overlaps its old time), locks and re-checks the new time live, moves the Google event in place (same event, same Meet link, guests updated) and emails both of you. Settings: **Changes allowed until N hours before** and **Reschedules per booking**.

Upgrading from 1.2 creates one type from your previous length and start-time settings; nothing else changes until you add more. Old emailed cancel links keep working and open the new page.

## Styling

The calendar always uses the surrounding page's font and text color. On top of that, **Appointments → Settings → Appearance** has a live preview rendered inside your actual theme, and these options:

| Option | "Match my theme" does | Or |
| --- | --- | --- |
| Accent color (selected day, bars, links) | Block themes: the theme's button color from theme.json (as a `--wp--preset--color--*` variable, so style variations follow). Classic themes: reads the theme's real button / link color from the page. | Any color, with your theme's palette as swatches |
| Booking button and form fields | Uses the theme's own button and input styles (`.wp-element-button` on block themes, plain `button` / `input` rules on classic themes) | The calendar's own style in the accent color |
| Corners | The theme's button radius | Square, slightly rounded, rounded, very rounded |
| Calendar background | None (page shows through) | The theme's page color, or any color |
| Spacing | — | Compact, comfortable, roomy |
| Headings | The theme's heading font | Same font as the surrounding text |
| Custom CSS | — | Loaded only on pages with the calendar |

### Per block or per shortcode

The block's sidebar has **Calendar style** and **Calendar colors** panels (overrides for that block only), and it supports the editor's standard **Color**, **Typography** and **Dimensions** controls. The editor shows the real widget with sample times.

Shortcode attributes override the site settings the same way:

```
[anderson_booking heading="Book a call" accent="#7c3aed" buttons="plugin" radius="round" density="compact" background="#faf5ff" headings="inherit"]
```

`buttons`: `theme` | `plugin` · `radius`: `auto` | `square` | `soft` | `rounded` | `round` · `density`: `compact` | `comfortable` | `roomy` · `background`: `none` | `theme` | a hex color · `headings`: `theme` | `inherit`

### From your theme's CSS

Everything is driven by custom properties declared at zero specificity, so a plain `.aab { … }` rule in your theme wins:

```css
.aab {
  --aab-accent: #0b5fff;       /* selected day, bars, links, plugin-style button */
  --aab-on-accent: #fff;       /* text on the accent */
  --aab-radius: 6px;           /* outer corners; controls derive smaller radii */
  --aab-space: 1;              /* spacing multiplier */
  --aab-surface: transparent;  /* calendar box background */
  --aab-text: inherit;
  --aab-line: rgba(0,0,0,.15); /* borders */
  --aab-soft: rgba(0,0,0,.04); /* open-day background */
  --aab-muted: #6b7280;        /* secondary text */
  --aab-tint: rgba(11,95,255,.1);
  --aab-error: #c0392b;
  --aab-heading-font: inherit;
  --aab-max-width: 760px;
}
```

Parts: `.aab__heading`, `.aab__body`, `.aab__cal`, `.aab__month-label`, `.aab__nav`, `.aab__day` (`.is-open`, `.is-selected`), `.aab__bar`, `.aab__panel`, `.aab__panel-title`, `.aab__time`, `.aab__form`, `.aab__field`, `.aab__input`, `.aab__submit`, `.aab__done`, `.aab__tz`. Calendar buttons (`.aab__day`, `.aab__time`, `.aab__nav`, `.aab__link`) are protected from theme `button` rules with `.aab:not(#aab-x) …`; use the same prefix (or the variables) to restyle them directly.

Filters for developers:

- `aab_enqueue_styles` – return `false` to drop the plugin stylesheet and style everything yourself.
- `aab_style_vars` – change the CSS variables applied to each widget.
- `aab_theme_tokens` – adjust what was read from the theme (accent, radius, heading font, palette).

## Running it in production

- **Hosting behind a proxy or cache** (Cloudways/Varnish, nginx, Cloudflare): the visitor's real IP is read from `CF-Connecting-IP` / `X-Real-IP` / `X-Forwarded-For`, but only when the request reaches PHP from a private or loopback address, so it can't be spoofed from the internet. Override with the `aab_client_ip` filter if your setup differs. Page caches are fine: availability is loaded fresh through the REST API, which sends `Cache-Control: no-store`.
- **Background tasks** run hourly through WP-Cron: Google sync, reminders, record clean-up. If your host disables WP-Cron (`DISABLE_WP_CRON`), add a server cron job that requests `wp-cron.php` every few minutes. **Tools → Site Health** warns if the task stops running, if Google access breaks, or if the site timezone is a fixed UTC offset (which breaks daylight saving time).
- **When Google access breaks** (revoked, password change, or an OAuth app left in *Testing*), booking stops safely, the owner gets one email, and a notice appears in wp-admin until you reconnect.
- **Google hiccups** (timeouts, 429, 5xx) are retried twice with backoff; event creation uses a client-generated ID, so a retry can never create a duplicate event. Recent errors are listed on the settings page.
- **All-day events** in Google Calendar (vacations, holidays) are *Free* by default and don't block times. Mark them *Busy* or list the dates under **Days off**.
- **Privacy**: booking records can be deleted automatically N days after the appointment; personal-data export/erase tools are supported; bookings can be exported as CSV (cells are protected against spreadsheet formula injection).
- **Translations**: `languages/anderson-appointment-booking.pot`.
- **CI**: GitHub Actions checks PHP syntax on 7.4–8.4 and JavaScript syntax on every push.

## How it works

| Piece | Where |
| --- | --- |
| Google OAuth 2.0 + Calendar v3 client (WordPress HTTP API) | `includes/class-aab-google.php` |
| Slot calculation (weekly hours → candidates → minus busy + buffers) | `includes/class-aab-availability.php` |
| Bookings (private post type), lock + live re-check, emails, cancel | `includes/class-aab-bookings.php` |
| Public REST API: `GET aab/v1/availability`, `POST aab/v1/bookings`, `POST aab/v1/bookings/{id}/reschedule` | `includes/class-aab-rest.php` |
| Theme detection and style resolution | `includes/class-aab-style.php` |
| Shortcode, block, appearance preview, visitor cancel page | `includes/class-aab-frontend.php` |
| Hourly sync, reminders, retention, Site Health checks | `includes/class-aab-maintenance.php` |
| Appointment types and question validation | `includes/class-aab-types.php` |
| Appointment types admin screen | `includes/class-aab-admin-types.php`, `assets/js/admin-types.js` |
| Settings screen and bookings list | `includes/class-aab-admin.php`, `includes/views/` |
| Widget | `assets/js/booking.js`, `assets/css/booking.css` |

Scopes requested: `calendar.events`, `calendar.freebusy`, `calendar.calendarlist.readonly`. Only busy/free blocks are read from conflict calendars — never event titles or details.

Busy times are cached for two minutes per range and the cache is cleared whenever a booking is made or cancelled. If Google can't be reached, no times are offered (it fails closed rather than risking a double booking).

Spam protection: honeypot field, a minimum time on the form, and per-IP rate limits (5 bookings/hour, 20 attempts/hour). Behind a reverse proxy, use the `aab_client_ip` filter to supply the real client IP.

### Hooks

- `do_action( 'aab_booking_confirmed', $booking )`
- `do_action( 'aab_booking_cancelled', $booking, $by )` — `$by` is `visitor` or `admin`
- `do_action( 'aab_booking_rescheduled', $booking, $old_start )`
- `apply_filters( 'aab_client_ip', $ip )`
- Styling filters: see [Styling](#styling)

### Cancelling

Visitors use the link in their confirmation email (it shows a confirm button first, so mail-app link scanners can't cancel by accident). You can cancel from **Appointments → Bookings** (row action), or trash an upcoming booking; both delete the Google event and email the visitor.

## Research and roadmap

- [Competitive analysis](docs/research/competitive-analysis.md): how the WordPress booking plugins compare and where this one wins.
- [Roadmap](docs/ROADMAP.md)

## License

MIT © Jeremy Anderson
