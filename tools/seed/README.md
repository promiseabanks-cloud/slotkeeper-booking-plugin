# Seed data — Prime Precision salon

One-off seeders that load a real booking catalog into SlotKeeper. They are
developer tools, not part of the plugin runtime, so nothing here is loaded by
`slotkeeper.php`.

## Prime Precision

- `prime-precision-catalog.json` — the salon's catalog exported from the live
  FluentBooking site (2026-11-05): one shared stylist, weekly hours, and 76
  hairstyle services with duration, USD price, and paid/free flag.
- `seed-prime-precision.php` — creates the provider, the provider's weekly
  hours, and the 76 services as `staff` resources assigned to that provider.

Run it on the target site (SlotKeeper must be active):

```
wp eval-file wp-content/plugins/slotkeeper/tools/seed/seed-prime-precision.php
```

It is **idempotent** — each service row carries its `seed_source_id` in meta,
so re-running updates the matching rows instead of duplicating, and the
provider's weekly rules are rebuilt each run.

### Overnight-hours caveat

The salon runs 12:00–03:00 the next morning, every day. SlotKeeper models
availability within a single day, so each day is seeded as two windows
(12:00–23:59 and 00:00–03:00). This reproduces the open/closed hours, but a
single booking cannot be generated across midnight, and the one 12-hour style
("Large Knotless Thigh Length", 720 min) will not fit the noon–midnight
window. A proper cross-midnight availability change is tracked separately.
