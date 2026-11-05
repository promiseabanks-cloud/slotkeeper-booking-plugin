=== SlotKeeper ===
Contributors: bankssolution
Tags: booking, appointments, reservations, rentals, classes
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 0.1.0
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

= 0.1.0 =
* Initial build: schema, settings, resources, availability, admin-created
  bookings across all four booking types, email + WhatsApp notifications,
  Paystack checkout-link adapter (not yet wired to a live payment flow).
* Public booking widget, Google Calendar sync, SMS, and Flutterwave are not
  yet implemented.
