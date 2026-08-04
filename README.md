# Order Status URL

Provides a UUID-based "check my order" page for **guest** Drupal Commerce
orders, gated behind an email-match check and a CAPTCHA challenge. The
URL path segment is configurable via an admin settings form, and
defaults to `order-status`.

## What it does

- Adds the route `/order-status/{uuid}` (path segment configurable —
  see below).
- Looks up the `commerce_order` entity with that UUID.
- Rejects the request (404) if:
  - no order has that UUID, or
  - the order belongs to a registered customer (`uid != 0`) — those
    customers should use `/user/{user}/orders/{commerce_order}` instead,
    which already has proper access control.
- On first visit, shows a form asking for the email used at checkout,
  plus a CAPTCHA challenge.
- On successful match, remembers verification in the session and shows
  the order status page (state, placed date, total, line items,
  shipment/tracking info if applicable).

## Requirements

- Drupal Commerce (`commerce`, `commerce_order`).
- The contributed [Token module](https://www.drupal.org/project/token),
  used to expose the `[commerce_order:order-status-url]` token (see
  below).
- The contributed [CAPTCHA module](https://www.drupal.org/project/captcha).
  Optionally also install
  [Image CAPTCHA](https://www.drupal.org/project/captcha) (bundled with
  the CAPTCHA module) or
  [reCAPTCHA](https://www.drupal.org/project/recaptcha) if you'd rather
  use Google's widget.

## Installation

1. Place this module in `modules/custom/order_status_url`.
2. Install Token and CAPTCHA if not already enabled:
   ```
   composer require drupal/token drupal/captcha
   drush en token captcha image_captcha -y
   ```
3. Enable this module:
   ```
   drush en order_status_url -y
   ```
4. Configure the default CAPTCHA challenge type at
   `/admin/config/people/captcha`. The form uses
   `'#captcha_type' => 'default'`, so it automatically follows whatever
   challenge you set there (image, math, reCAPTCHA, etc.) — no code
   changes needed if you switch challenge types later.

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

For places that don't support tokens — a custom event subscriber, a
checkout pane render array, etc. — build the URL directly wherever you
have a loaded `$order`:

```php
use Drupal\Core\Url;

$url = Url::fromRoute('order_status_url.guest_order_status', [
  'uuid' => $order->uuid(),
])->setAbsolute()->toString();
```

Because the link is generated from the route name (not a hard-coded
path), it automatically reflects whatever path segment is currently
configured — no code changes needed if you later change the setting.

If the target does support tokens (e.g. the order receipt email body),
use `[commerce_order:order-status-url]` instead — see "Using the token
instead" below.

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

## Notes / things you may want to extend

- **Session-scoped verification.** Once a visitor verifies with the
  correct email, they won't be re-prompted for that UUID again in the
  same session. Remove/shorten this if you'd rather re-verify every
  visit.
- **Rate limiting.** The CAPTCHA slows down brute-force guessing of
  emails against a known UUID; consider also enabling Drupal core's
  flood control or a module like Login Security if you want to throttle
  repeated failed attempts by IP.
- **"Find my order" entry point.** This module assumes the guest has
  the direct link (from email/receipt). If you want a page where they
  can enter an order number + email and get redirected to their UUID
  URL (for when the original email is lost), that's a natural
  follow-up form to add — ask if you'd like it built out.
- **Template overrides.** The status page uses the theme hook
  `order_status_page` (template `order-status-page.html.twig`) with a
  suggestion `order_status_page__{order_bundle}` so you can override it
  per order type in your theme.
