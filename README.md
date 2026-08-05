# Order Status URL

Provides a UUID-based "check my order" page for **guest** Drupal Commerce
orders, gated behind an email-match check with optional CAPTCHA
protection. The URL path segment is configurable via an admin settings
form, and defaults to `order-status`.

## What it does

- Adds the route `/order-status/{uuid}` (path segment configurable —
  see below).
- Looks up the `commerce_order` entity with that UUID.
- Rejects the request (404) if:
  - no order has that UUID, or
  - the order belongs to a registered customer (`uid != 0`) — those
    customers should use `/user/{user}/orders/{commerce_order}` instead,
    which already has proper access control.
- On first visit, shows a form asking for the email used at checkout.
  If the CAPTCHA module is installed and enabled, a CAPTCHA challenge
  is added automatically; otherwise the email check alone gates
  access.
- On successful match, remembers verification in the session and shows
  the order status page (state, placed date, total, line items,
  shipment/tracking info if applicable).

## Requirements

- Drupal Commerce (`commerce`, `commerce_order`).
- The contributed [Token module](https://www.drupal.org/project/token),
  used to expose the `[commerce_order:order-status-url]` token (see
  below).
- **Optional:** the contributed
  [CAPTCHA module](https://www.drupal.org/project/captcha). If it's
  installed and enabled, a "Require CAPTCHA" setting appears on this
  module's settings form and a CAPTCHA element is added to the
  verification form automatically. If it's not installed, the module
  works fine without it — the email check is the only gate.

## Installation

### Option A: via Composer (this repository)

This module isn't on Packagist or drupal.org, so the site's root
`composer.json` needs a VCS repository entry pointing at this GitHub
repo before it can be required normally:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/tvalimaa/order_status_url"
        }
    ]
}
```

Then require it like any other package:

```
composer require tvalimaa/order_status_url:^1.0
```

Composer will resolve `drupal/commerce` and `drupal/token`
automatically from the module's own `composer.json`. `drupal/captcha`
is listed as a Composer `suggest`, not a hard dependency — add it
separately if you want CAPTCHA protection (see below). Because the
module's `type` is `drupal-custom-module`, a standard
`drupal/recommended-project`-based site will place it under
`web/modules/custom/order_status_url` automatically via its existing
`installer-paths` configuration — no extra path mapping needed.

> **Note:** Composer's VCS repository resolves version constraints
> like `^1.0` from git tags. Push a `1.0.0` tag to this repo before
> requiring it that way, or use a branch reference instead — e.g.
> `composer require tvalimaa/order_status_url:dev-main` if you haven't
> tagged a release yet.

Once required, enable it:

```
drush en order_status_url -y
```

### Option B: manual placement

1. Place this module in `modules/custom/order_status_url`.
2. Install Token if not already enabled:
   ```
   composer require drupal/token
   drush en token -y
   ```
3. Enable this module:
   ```
   drush en order_status_url -y
   ```
4. **Optional — CAPTCHA protection.** Install and enable CAPTCHA (and
   a challenge plugin such as Image CAPTCHA):
   ```
   composer require drupal/captcha
   drush en captcha image_captcha -y
   ```
   Once enabled, a "Require CAPTCHA on the email verification form"
   checkbox appears on this module's settings form, checked by
   default. The form uses `'#captcha_type' => 'default'`, so it
   automatically follows whatever challenge type is configured at
   `/admin/config/people/captcha` — no code changes needed if you
   switch challenge types later. If CAPTCHA is never installed, the
   verification form simply omits the challenge and relies on the
   email check alone.

## Configuring the URL path

Go to **Commerce → Configuration → Order Status URL**
(`/admin/commerce/config/order-status-url`), or use the "Configure" link
next to the module on the Extend page.

- The path segment defaults to `order-status`, producing URLs like
  `/order-status/3f2b1c8e-....`
- Enter a different value (letters, numbers, hyphens, slashes) to
  change it — e.g. `check-my-order` produces
  `/check-my-order/{uuid}`.
- Saving the form rebuilds the router immediately, so the new path is
  live right away. Old links using the previous path segment will stop
  working once changed, so update anywhere you generate the URL (see
  below) before or immediately after changing this setting.

Under the hood, the route's path is rewritten at route-build time by
`Drupal\order_status_url\Routing\RouteSubscriber`, which reads the
`path` key from the `order_status_url.settings` config and falls back
to `order-status` if it's empty — so the module works out of the box
with no configuration needed.

## Generating the link

Wherever you have a loaded `$order` (checkout completion pane, order
receipt email, a `commerce_order.place.post_transition` event
subscriber, etc.):

```php
use Drupal\Core\Url;

$url = Url::fromRoute('order_status_url.guest_order_status', [
  'uuid' => $order->uuid(),
])->setAbsolute()->toString();
```

Because the link is generated from the route name (not a hard-coded
path), it automatically reflects whatever path segment is currently
configured — no code changes needed if you later change the setting.

Typical places to surface it:

- The order confirmation / receipt email (override
  `commerce_order.receipt` mail template or use
  `hook_mail_alter()` / a mail event subscriber).

## Using the token instead

For anywhere that already supports tokens — the order receipt email
body/subject, order notification templates, Rules/ECA actions, etc. —
you don't need any PHP at all. This module exposes:

```
[commerce_order:order-status-url]
```

which resolves to the same absolute URL as the code snippet above,
built from the order's UUID and the currently configured path. It's
implemented via `hook_token_info()` / `hook_tokens()` in
`order_status_url.module`, attached to the `commerce_order` token type
that the Token module automatically generates for the order entity.

To find it in a token-aware field's "Browse available tokens" list,
look under **Order → Order status URL**.
- The checkout "Complete" step's render array.
