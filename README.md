# SlotKeeper

A configurable WordPress booking plugin by Banks Solution. One plugin, four booking types:

- **Appointments** (staff + services)
- **Reservations** (tables / venues)
- **Rentals** (date ranges)
- **Classes** (capacity-limited sessions)

## Layout

```
slotkeeper/              <- the plugin; zip this folder to install
  slotkeeper.php         plugin entry point
  includes/
    Admin/               wp-admin screens (dashboard, bookings, resources, availability, settings, integrations)
    Domain/              booking creation (double-booking-safe) and availability calculation
    Repositories/        database access
    Rest/                public REST API (widget) and Paystack webhook
    Payments/            Paystack gateway (Flutterwave is a stub)
    Notifications/       email, WhatsApp (Meta Cloud API); SMS is a stub
    Calendar/            Google Calendar interface (sync not implemented)
    Frontend/            [slotkeeper_booking] and [slotkeeper_manage_booking] shortcodes
    Support/             settings and encrypted credential storage
  assets/                widget and admin CSS/JS
```

## Install

1. Zip the `slotkeeper/` folder and upload it via Plugins > Add New > Upload, or copy it to `wp-content/plugins/`.
2. Activate, then go to SlotKeeper > Settings and enable the booking types you use.
3. Add resources and availability, then place `[slotkeeper_booking]` on a page.

Optional shortcode attributes: `resource_id="12"` to pin one resource, `type="..."` to limit to one booking type.

## Credentials

Paystack and WhatsApp credentials are entered under SlotKeeper > Integrations and stored encrypted. They can alternatively be defined as constants in `wp-config.php`, which take priority:

- `SLOTKEEPER_PAYSTACK_SECRET_KEY`
- `SLOTKEEPER_WHATSAPP_META_TOKEN`
- `SLOTKEEPER_WHATSAPP_META_PHONE_ID`
- `SLOTKEEPER_GOOGLE_SERVICE_ACCOUNT_JSON`

Never commit real keys to this repository.

## Status

Version 0.3.2, running on a sandbox site. Not yet released.

Implemented: admin backend, public booking widget, guest self-service cancellation, email and WhatsApp notifications, Paystack checkout with webhook and reconcile-on-lookup fallback.

Not implemented: Flutterwave, SMS, Google Calendar sync.

`slotkeeper/readme.txt` is the WordPress-style readme and is out of date (it still says 0.1.0).
