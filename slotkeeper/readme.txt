=== SlotKeeper ===
Contributors: bankssolution
Tags: booking, appointments, reservations, rentals, classes
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A configurable booking engine for any business — appointments, table/venue
reservations, rentals, or capacity-limited classes — running on your own
WordPress site.

== Description ==

SlotKeeper is a standalone WordPress plugin, not a hosted service: install it
on one site, turn on the booking type(s) your business needs in Settings, add
your resources (staff, tables, rental items, or class sessions) and their
availability, and start taking bookings.

v0.1.0 covers the admin side end to end: resources, availability, and booking
management, with email and WhatsApp (Meta Cloud API) notifications and a
Paystack payment adapter ready to connect. The public-facing customer booking
widget is still in development — see CHANGELOG.md.

== Installation ==

1. Upload the `slotkeeper` folder to `/wp-content/plugins/`.
2. Activate through the "Plugins" screen.
3. Go to SlotKeeper > Settings, set your business name/currency, and enable
   the booking type(s) you use.
4. Go to SlotKeeper > Resources to add what you're booking.
5. If it's appointments or reservations, go to SlotKeeper > Availability to
   set weekly hours.

To enable WhatsApp or Paystack, add the relevant constants to `wp-config.php`
(see BACKEND_SETUP.md in the project root for exact names — never commit
real key values anywhere).

== Changelog ==

= 0.5.0 =
* Providers (shared availability): added a Providers screen and a `sk_providers`
  table (DB v5). A resource can be assigned to a provider and then shares that
  provider's weekly hours and date exceptions instead of keeping its own — so
  one stylist offering many services needs a single schedule, not one per
  service.
* Shared occupancy + locking: bookings of any service belonging to the same
  provider now count against one shared calendar, and the double-booking lock
  is taken on the provider, so a provider can't be booked into two different
  services at the same time. Resources with no provider keep their own
  schedule and occupancy exactly as before.
* Availability screen now edits either a provider's shared schedule or an
  individual resource's; Resources screen gained an optional provider selector.

= 0.4.0 =
* Payments: added Stripe (Checkout) and PayPal (Orders v2) gateways alongside
  Paystack, all behind the existing Payment_Gateway interface and resolved
  through Gateway_Registry. Webhook receiver generalised to a per-provider
  route (/webhooks/paystack|stripe|paypal) with each provider's own signature
  verification; the on-return verify() fallback still confirms payment when a
  webhook is missing.
* Deposits: a booking can now collect a configurable deposit at booking time
  (full price, a percentage, or a fixed amount, set in Settings, overridable
  per resource via its meta) instead of always the full price; the balance is
  settled in person. The full price is recorded as sk_payments.total_minor
  (DB v4) so the outstanding balance stays derivable.
* Added Support\Money (currency minor/major-unit helpers) and Support\Deposit
  (deposit policy) helpers. Integrations screen gained Stripe and PayPal
  credential fields; Settings gained deposit controls and a PayPal
  live/sandbox switch.

= 0.1.0 =
* Initial build: schema, settings, resources, availability, admin-created
  bookings across all four booking types, email + WhatsApp notifications,
  Paystack checkout-link adapter (not yet wired to a live payment flow).
* Public booking widget, Google Calendar sync, SMS, and Flutterwave are not
  yet implemented.
