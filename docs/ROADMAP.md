# Roadmap

Based on the [competitive analysis](research/competitive-analysis.md). Principle: **every feature free, no add-ons**, built for solo professionals and small teams who live in Google Calendar.

## Done

- **1.0** Google Calendar availability and booking, Meet links, emails, cancel links, privacy tools.
- **1.1** Theme matching (block and classic themes), appearance settings with live in-theme preview, per-block and shortcode styling, CSS variables.
- **1.2** Production hardening: atomic slot lock, proxy-aware rate limits, Google retries without duplicates, hourly sync of moves and deletions, reminders, days off, per-person cap, phone field, Site Health checks, owner alerts, CSV export, retention.
- **1.3** Appointment types (own length, start-time spacing, hours, location, video call), custom booking questions, visitor self-service rescheduling with a change cutoff, one manage page for reschedule and cancel.

## Next

### 1.4: Get paid, reach more calendars
- [ ] Stripe Checkout for paid appointment types (price per type, pay-to-confirm, refunds on cancel within policy). Free, no add-on.
- [ ] Microsoft 365 / Outlook calendar (Graph API) as an alternative or extra conflict calendar.
- [ ] iCloud and other CalDAV calendars as conflict calendars.

### 1.5: Smarter scheduling
- [ ] "Pack my day" option: prefer slots adjacent to existing meetings to avoid fragmented days.
- [ ] Per-day caps per type, "at most N calls per day", protected focus blocks.
- [ ] Travel buffers for in-person appointment types.
- [ ] Group sessions (N seats per slot).

### 1.6: Booking links and integrations
- [ ] Built-in `/book` page and per-type links, ready for email signatures.
- [ ] Pre-filled links (`?name=&email=`) and single-use links for sales follow-ups.
- [ ] Webhooks (booking created / moved / cancelled) and a documented REST API.
- [ ] MCP endpoint so AI assistants can check availability and book; schema.org markup for AI search.

### Ongoing
- [ ] WCAG 2.2 AA audit of the widget and manage page, results published.
- [ ] WordPress.org listing, demo site, setup video for the Google step.
- [ ] Automated end-to-end tests (PHPUnit + Playwright) in CI.
