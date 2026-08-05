<?php

namespace Drupal\order_status_url\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormBuilderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for the guest order status lookup page.
 */
class OrderStatusController extends ControllerBase {

  /**
   * The form builder.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilderService;

  /**
   * Constructs a new OrderStatusController.
   *
   * @param \Drupal\Core\Form\FormBuilderInterface $form_builder
   *   The form builder service.
   */
  public function __construct(FormBuilderInterface $form_builder) {
    $this->formBuilderService = $form_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('form_builder')
    );
  }

  /**
   * Session key prefix used to remember verified UUIDs.
   */
  const SESSION_KEY_PREFIX = 'order_status_url_verified:';

  /**
   * Loads an order by UUID and shows status once the email is verified.
   *
   * @param string $uuid
   *   The order UUID, taken from the route.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return array|\Symfony\Component\HttpFoundation\Response
   *   Either the verification form or a render array with order status.
   */
  public function status($uuid, Request $request) {
    $order = $this->loadOrderByUuid($uuid);

    if (!$order) {
      // Do not reveal whether the UUID was malformed or simply not found.
      throw new NotFoundHttpException();
    }

    // This route is for guest orders only. Authenticated customers should
    // use the standard /user/{user}/orders/{commerce_order} route, which
    // already has proper access control tied to their account.
    if ((int) $order->getCustomerId() !== 0) {
      throw new NotFoundHttpException();
    }

    $session = $request->getSession();
    $session_key = self::SESSION_KEY_PREFIX . $uuid;

    if (!$session->get($session_key)) {
      // Not verified yet in this session: show the email + CAPTCHA form.
      // Forms are inherently uncacheable in Drupal (unique build/CSRF
      // tokens per request), so no explicit #cache handling is needed
      // here.
      $build = $this->formBuilderService->getForm(
        '\Drupal\order_status_url\Form\OrderStatusVerifyForm',
        $order
      );
    }
    else {
      $build = [
        '#theme' => 'order_status_page',
        '#order' => $order,
        '#status_label' => $order->getState()->getLabel(),
        // CRITICAL: this render array contains another customer's
        // order data and must never be shared across sessions via
        // Drupal's Internal/Dynamic Page Cache. Without this, a
        // *different* anonymous visitor requesting the exact same
        // URL could be served this visitor's cached order data
        // without ever passing the email verification form.
        '#cache' => [
          'max-age' => 0,
        ],
      ];
    }

    $this->addPrivacyHeaders($build);

    return $build;
  }

  /**
   * Attaches headers that keep this page out of search/AI indexes and
   * stop the browser leaking the UUID-bearing URL to third parties.
   *
   * @param array $build
   *   The render array to attach headers to, by reference.
   */
  protected function addPrivacyHeaders(array &$build) {
    // Keep crawlers (search engines and AI crawlers that respect
    // standard robots directives) from indexing or archiving this
    // URL, in case a link ever ends up somewhere public.
    $build['#attached']['http_header'][] = [
      'X-Robots-Tag',
      'noindex, nofollow, noarchive, nosnippet',
    ];

    // Prevent the browser from sending this UUID-bearing URL as the
    // Referer header to any third-party resource the page loads
    // (analytics, fonts, embedded content, etc.).
    $build['#attached']['http_header'][] = [
      'Referrer-Policy',
      'no-referrer',
    ];
  }

  /**
   * Loads a commerce order entity by its UUID.
   *
   * @param string $uuid
   *   The UUID to look up.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface|null
   *   The order, or NULL if none was found.
   */
  protected function loadOrderByUuid($uuid) {
    $storage = $this->entityTypeManager()->getStorage('commerce_order');
    $orders = $storage->loadByProperties(['uuid' => $uuid]);
    return $orders ? reset($orders) : NULL;
  }

}
