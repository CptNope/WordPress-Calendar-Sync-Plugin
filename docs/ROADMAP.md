# Roadmap

Based on the [competitive analysis](research/competitive-analysis.md). Principle: **every feature free, no add-ons**, built for solo professionals and small teams who live in Google Calendar.

## Done

- **1.0** Google Calendar availability and booking, Meet links, emails, cancel links, privacy tools.
- **1.1** Theme matching (block and classic themes), appearance settings with live in-theme preview, per-block and shortcode styling, CSS variables.
- **1.2** Production hardening: atomic slot lock, proxy-aware rate limits, Google retries without duplicates, hourly sync of moves and deletions, reminders, days off, per-person cap, phone field, Site Health checks, owner alerts, CSV export, retention.
- **1.3** Appointment types (own length, start-time spacing, hours, location, video call), custom booking questions, visitor self-service rescheduling with a change cutoff, one manage page for reschedule and cancel.
- **1.4** Stripe payments (hold, pay, confirm, automatic refunds by policy) and Outlook / Microsoft 365 conflict calendars via Microsoft Graph. README rewritten with diagrams.

## Next

### 1.5: More calendars
- [ ] iCloud and other CalDAV calendars as conflict calendars.
- [ ] Outlook as the booking calendar (create events, Teams links) for people who don't use Google.
- [ ] Coupons and deposits for paid types.

### 1.6: Smarter scheduling
- [ ] "Pack my day" option: prefer slots adjacent to existing meetings to avoid fragmented days.
- [ ] Per-day caps per type, "at most N calls per day", protected focus blocks.
- [ ] Travel buffers for in-person appointment types.
- [ ] Group sessions (N seats per slot).

### 1.7: Booking links and integrations
- [ ] Built-in `/book` page and per-type links, ready for email signatures.
- [ ] Pre-filled links (`?name=&email=`) and single-use links for sales follow-ups.
- [ ] Webhooks (booking created / moved / cancelled) and a documented REST API.
- [ ] MCP endpoint so AI assistants can check availability and book; schema.org markup for AI search.

### Ongoing
- [ ] WCAG 2.2 AA audit of the widget and manage page, results published.
- [ ] WordPress.org listing, demo site, setup video for the Google step.
- [ ] Automated end-to-end tests (PHPUnit + Playwright) in CI.
