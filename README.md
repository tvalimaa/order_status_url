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

## Adding the link to the order receipt email

Commerce's `commerce-order-receipt.html.twig` is a fixed-layout
template with no configurable "extra content" area, so
`hook_preprocess_commerce_order_receipt()` alone can't make new markup
appear — the base template simply doesn't reference variables it
doesn't already know about, and there's no UI for this in Commerce.

**This module handles it automatically — no theme changes needed.**
Two pieces work together:

- `order_status_url_preprocess_commerce_order_receipt()` supplies an
  `order_status_url` variable (resolved via the token, `NULL` for
  registered-customer orders so it never shows there).
- `order_status_url_theme_registry_alter()` points the
  `commerce_order_receipt` theme hook at this module's own
  `templates/commerce-order-receipt.html.twig`, which `{% extends %}`
  Commerce's base template and overrides only the
  `additional_information` block (the one that prints "Thank you for
  your order!"), adding the link right below it — without duplicating
  the whole 150-line file.

Enabling the module is enough; run `drush cr` afterward since the
theme registry and Twig templates are both cached.

**If your theme already has its own `commerce-order-receipt.html.twig`
override**, that continues to take precedence automatically — this
module checks whether the theme registry's path for this hook still
points at `commerce_order`'s own templates directory before touching
it, and backs off if a theme has already claimed it. In that case, add
the link to your theme's override yourself, referencing the same
`order_status_url` variable (already supplied by the preprocess hook
regardless of which template ends up used):

```twig
{% block additional_information %}
  {{ 'Thank you for your order!'|t }}
  {% if order_status_url %}
    <p style="margin-top: 15px;">
      <a href="{{ order_status_url }}">{{ 'Check your order status'|t }}</a>
    </p>
  {% endif %}
{% endblock %}
```

**If you'd rather not touch templates or the theme registry at all**,
an alternative is `hook_mail_alter()`, which appends the link *after*
the entire rendered receipt instead of inside it:

```php
/**
 * Implements hook_mail_alter().
 */
function mymodule_mail_alter(array &$message) {
  if ($message['key'] !== 'order_receipt' || empty($message['params']['order'])) {
    return;
  }

  /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
  $order = $message['params']['order'];

  if ((int) $order->getCustomerId() !== 0) {
    return;
  }

  $link_html = \Drupal::token()->replace(
    '<p style="margin-top: 20px;"><a href="[commerce_order:order-status-url]">' . t('Check your order status') . '</a></p>',
    ['commerce_order' => $order]
  );

  $message['body'][] = \Drupal\Core\Render\Markup::create($link_html);
}
```

All three approaches use the same underlying token — pick whichever
fits how much you want this module to control versus your theme.

## Security considerations

**UUID guessing itself is not a realistic threat.** A UUIDv4 has 122
bits of randomness (~5.3×10³⁶ possible values) — brute-forcing a valid
one is computationally infeasible at any practical request rate.
The real exposure is around *leakage and caching* of a UUID once it
exists, which is what the points below address.

### Handled automatically by this module

- **Cross-session cache safety.** The render array for the "verified,
  showing order data" path is explicitly marked `'#cache' => ['max-age'
  => 0]`, so Drupal's Internal/Dynamic Page Cache will never cache and
  replay one visitor's order data to a different anonymous visitor
  requesting the same URL. (The verification form itself is inherently
  uncacheable already, being a Drupal form.)
- **`X-Robots-Tag: noindex, nofollow, noarchive, nosnippet`** is sent
  on every response from this route, so search engines and AI
  crawlers that respect standard robots directives won't index or
  archive it even if a link ends up somewhere public.
- **`Referrer-Policy: no-referrer`** is sent on every response from
  this route, so the browser won't leak the UUID-bearing URL to any
  third-party resource the page loads (analytics scripts, embedded
  content, etc.) via the `Referer` header.
- **Rate limiting via Drupal core's Flood API.** Failed email-match
  attempts are throttled per IP address (5 attempts / 15 minutes by
  default — see `OrderStatusVerifyForm::FLOOD_LIMIT` and
  `::FLOOD_WINDOW`), independently of whether CAPTCHA is installed.
  This is a baseline defense against scripted email-guessing even on
  sites that don't want CAPTCHA.
- **CAPTCHA**, when installed and enabled (see above), adds a further,
  harder-to-automate obstacle on top of the flood limit.

### Steps to take at the site/infrastructure level

These aren't things a module can configure for you, since they live in
site config or third-party services, but are worth doing given the
sensitivity of this URL:

- **`robots.txt`.** Add an explicit disallow rule for the configured
  path prefix (defaults to `order-status`), covering both the general
  crawler stanza and, if you want extra assurance, specific AI-crawler
  user agents:
  ```
  User-agent: *
  Disallow: /order-status/

  User-agent: GPTBot
  Disallow: /order-status/

  User-agent: CCBot
  Disallow: /order-status/

  User-agent: Google-Extended
  Disallow: /order-status/
  ```
  If you change the path segment via the settings form, update this
  accordingly — the module can't rewrite `robots.txt` for you (unless
  you're using a module like RobotsTxt that generates it from config,
  in which case add an equivalent rule there instead).
- **Exclude this route from analytics tracking.** This site has
  Matomo, Piwik Pro, and Google Tag installed — by default, all three
  will log the full page URL, UUID included, into their dashboards,
  where it's visible to anyone with analytics access and typically
  retained far longer than the guest actually needs the link to work.
  Use each tool's URL exclusion feature (e.g. Matomo's "Exclude URL
  Parameters/Actions" or a custom URL-rewrite/search-and-replace rule)
  to mask or drop the UUID segment before it's sent, or exclude the
  path prefix entirely from tracking.
- **CDN / reverse proxy caching.** If the site sits behind a CDN or
  Varnish in front of Drupal, double-check that layer also respects
  `Cache-Control`/`max-age=0` for this path — Drupal's own page cache
  is the one layer this module can influence directly; an external
  cache in front of it needs its own exclusion rule if it doesn't
  already honor Drupal's cache headers.
