# Competitive analysis: WordPress appointment booking (October 2026)

Research done on 2026-10-24 from published comparisons and vendor pages (sources at the end). Prices and tiers change often; recheck before quoting them publicly.

## Who we're building for

Solo professionals and small businesses who take bookings on **their own WordPress site** and live in **Google Calendar**: consultants, freelancers, coaches, agencies, therapists, tutors, trades. Not salons with ten staff and not equipment rental. That segment is well served by Amelia, Bookly and Booknetic, and competing there means years of staff, location and package features.

## The market at a glance

| Plugin | Free tier | Paid pricing (published) | Google Calendar sync | Notes from reviews |
| --- | --- | --- | --- | --- |
| **Amelia** | Lite on WordPress.org | Starter $49/yr; Standard $89/yr or $299 lifetime; Pro $149/yr or $449 lifetime; Elite $259/yr or $799 lifetime | Two-way sync requires **Pro** | Polished admin, events module, email/SMS/WhatsApp. Many production sites need Pro; mixed support reputation; confusing default setup |
| **Bookly** | Base plugin on WordPress.org | Pro $49/yr or $129 lifetime; Business $199/yr; Ultimate $399/yr; add-ons sold separately | Two-way sync is a **paid add-on** ("Advanced Google Calendar") | Long track record, big add-on catalog. Stripe and recurring bookings are separate add-ons, so costs stack up; dated admin UI; slow support reported |
| **Simply Schedule Appointments** | Basic on WordPress.org | Plus from $99/yr (renews $129); Pro from $199/yr; Business from $399/yr | On supported (paid) plans | Quick setup wizard, markets accessibility, reschedule from confirmation. Annual tiers single-site; team scheduling only on top tier; Stripe/PayPal only |
| **LatePoint** | Free on WordPress.org | Starter from $79/yr; Scale from $149/yr; Agency from $299/yr | **No two-way sync in free tier** | Modern admin, live-preview form customizer, all features on paid plans. Free tier: Stripe only, no customer dashboard |
| **FluentBooking** | Free on WordPress.org (calendar sync listed as free) | Solo $79/yr or $249 lifetime; 5 sites $199/yr; 50 sites $399/yr | Listed as a free feature | Calendly-style, one Pro license unlocks everything, part of WPManageNinja ecosystem. Already ships **MCP for AI agents**. Limited payment gateways, no customer portal |
| **Booknetic** | Free version on vendor site | Basic $45/yr up to Elite $299/yr | **Paid add-on** | Full multi-staff/multi-location system. Payments, calendar sync, SMS and white-label are add-ons |
| **Easy Appointments** | Free on WordPress.org | Pro extension $39–$499 one-time | **Pro** | Generous free multi-location tier, but dated form usually needs custom CSS, steep setup before slots appear |
| **BookingPress / WooCommerce Bookings** | Varies | Varies | Varies | Woo Bookings is heavy and store-oriented for simple calls |
| **Calendly / Cal.com (SaaS)** | Free plans with limits | Per-seat subscriptions | Native | Best-in-class booking links and UX, but data and branding live on a third-party service, and embeds don't match the site |

## Recurring pain points (where users are unhappy)

1. **Core features are paywalled.** Google Calendar sync, Stripe, recurring appointments, SMS and team scheduling sit behind higher tiers or separately sold add-ons. "Costs add up" is the most repeated complaint.
2. **The booking form doesn't look like the site.** Dated or foreign-looking widgets that need custom CSS (called out for Easy Appointments; generally true of plugin-shipped UIs).
3. **Setup is steep.** Several plugins need services, staff, locations and schedules configured before a single slot appears.
4. **Support quality varies.** Slow support is reported for several vendors on review sites.
5. **Two-way sync is unclear.** Comparisons repeatedly advise buyers to "check if the plugin supports two-way sync" because vendors are vague about it.

## Feature categories the leaders compete on

Setup and usability, calendar view, buffers and limits, recurring appointments, custom durations, group/multi-spot bookings, calendar sync, multiple calendars, locations, video (Zoom, Meet), SMS/WhatsApp, reminders, payments (Stripe, PayPal, refunds), custom booking questions, roles and permissions, REST API and webhooks, CRM/form integrations, multilingual, and lately **AI-agent booking via MCP** (FluentBooking; WooCommerce documents the same pattern).

## Where Anderson Appointment Booking stands (v1.2.0)

**Already ahead**

- Two-way Google Calendar behaviour free: live free/busy, events created, deletions and moves in Google mirrored back hourly.
- Theme matching: reads theme.json tokens on block themes and probes real button/link/heading styles on classic themes. No competitor found doing this.
- Lightweight: visitor-side JS ≈ 8 KB gzipped, CSS ≈ 3.5 KB gzipped, no libraries, loaded only where the widget appears.
- Correctness: atomic slot lock, fails closed when Google can't be checked, idempotent event creation, retries with backoff, Site Health checks, owner alert when access breaks.
- Privacy: data stays on the site, retention setting, personal-data export/erase, CSV with formula-injection protection.

**Missing table stakes (closed in 1.3.0)**

- Multiple appointment types with their own length, hours and questions.
- Visitor self-service rescheduling.
- Custom booking questions.

**Missing, planned**

- Payments (Stripe Checkout) for paid sessions, free.
- Microsoft 365 / Outlook and iCloud (CalDAV) calendars.

## Positioning

> The booking calendar that feels like part of your site, never double-books, and keeps Google Calendar in sync, with every feature free.

Win on: **everything free, no add-ons**, **looks native on any theme**, **provably correct**, **private (no SaaS)**, **fast**.

## Sources

- [7 Best WordPress Appointment Booking Plugins in 2026: Tested & Compared (fs-code)](https://www.fs-code.com/blog/best-wordpress-appointment-booking-plugins)
- [Best WordPress Appointment Booking Plugins for 2026 (fs-poster)](https://www.fs-poster.com/blog/best-wordpress-appointment-booking-plugins)
- [Best WordPress Booking Plugins in 2026: Every Booking Type, Compared (fs-code)](https://www.fs-code.com/blog/best-wordpress-booking-plugins-to-fully-automate-your-business)
- [Top 7 WordPress booking plugins with Google Calendar integration in 2026 (wpreset)](https://wpreset.com/top-7-best-wordpress-booking-plugins-with-google-calendar-integration-in-2026/)
- [FluentBooking vs Amelia (fluentbooking.com)](https://fluentbooking.com/fluentbooking-vs-amelia/)
- [FluentBooking + Google Calendar (fluentbooking.com)](https://fluentbooking.com/integrations/google-calender/)
- [Top 10 BookingPress Alternatives (wpmanageninja)](https://wpmanageninja.com/bookingpress-alternatives/)
- [FluentBooking: MCP for AI agents (docs)](https://docs.fluentbooking.com/mcp-for-ai-agents)
- [Connect Booking & Appointment Plugin to AI Assistants Using MCP (WooCommerce)](https://woocommerce.com/document/connect-booking-appointment-plugin-to-ai-assistants-using-mcp/)
- [9 Best Simply Schedule Appointments Alternatives (booking-wp-plugin)](https://www.booking-wp-plugin.com/blog/simply-schedule-appointments-alternatives/)
- [10 Best WordPress Booking Plugins 2026 (booking-wp-plugin)](https://www.booking-wp-plugin.com/blog/10-best-wordpress-booking-plugins-2026/)
- [10 Best Calendly Alternatives for WordPress in 2026 (booking-wp-plugin)](https://www.booking-wp-plugin.com/blog/best-calendly-alternatives/)
- [Top 6 WordPress Appointment Booking Plugins in 2026 (jeweltheme)](https://jeweltheme.com/blog/wordpress-appointment-booking-plugins)
- [WordPress appointment booking plugins (wbcomdesigns)](https://wbcomdesigns.com/wordpress-appointment-booking-plugins/)
- [Calendly pricing (cal.com blog)](https://cal.com/blog/calendly-pricing)
- [Cal.com vs Calendly (usecarly)](https://www.usecarly.com/blog/cal-com-vs-calendly)
- [Booknetic reviews (Capterra)](https://www.capterra.ca/software/1013191/booknetic)
