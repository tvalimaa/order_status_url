<?php

namespace Drupal\order_status_url\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Verifies a guest's email address before revealing order status.
 *
 * A CAPTCHA element is included, when the CAPTCHA module is installed
 * and enabled via the settings form, to slow down automated attempts
 * to brute-force email addresses against a known order UUID. If
 * CAPTCHA isn't available or is turned off, the email check alone
 * gates access.
 *
 * Independently of CAPTCHA, Drupal core's Flood API throttles repeated
 * failed attempts by IP address, so a baseline rate limit applies even
 * on sites that don't have CAPTCHA installed.
 */
class OrderStatusVerifyForm extends FormBase {

  /**
   * Maximum failed attempts allowed within the flood window, per IP.
   */
  const FLOOD_LIMIT = 5;

  /**
   * Flood window, in seconds (15 minutes).
   */
  const FLOOD_WINDOW = 900;

  /**
   * Flood event name used for this form's attempts.
   */
  const FLOOD_EVENT = 'order_status_url.verify_attempt';

  /**
   * The module handler.
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The flood service.
   */
  protected FloodInterface $flood;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = new static();
    $instance->moduleHandler = $container->get('module_handler');
    $instance->configFactory = $container->get('config.factory');
    $instance->flood = $container->get('flood');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'order_status_verify_form';
  }

  /**
   * Determines whether the CAPTCHA challenge should be shown.
   *
   * Requires both that the captcha module is installed/enabled and
   * that the "Require CAPTCHA" setting is turned on.
   *
   * @return bool
   *   TRUE if the captcha element should be added to the form.
   */
  protected function captchaEnabled() {
    if (!$this->moduleHandler->moduleExists('captcha')) {
      return FALSE;
    }
    return (bool) $this->configFactory
      ->get('order_status_url.settings')
      ->get('captcha_enabled');
  }

  /**
   * {@inheritdoc}
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param \Drupal\commerce_order\Entity\OrderInterface|null $order
   *   The order being looked up, passed in from the controller.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?OrderInterface $order = NULL) {
    if (!$order) {
      $form_state->set('order_uuid', NULL);
      $form_state->set('order_email', NULL);
      return $form;
    }

    // Rate-limit by IP before rendering the form at all. This throttles
    // scripted attempts to guess the email address for a known UUID,
    // independently of whether CAPTCHA is installed.
    if (!$this->flood->isAllowed(self::FLOOD_EVENT, self::FLOOD_LIMIT, self::FLOOD_WINDOW)) {
      $form['rate_limited'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('Too many attempts. Please try again later.') . '</p>',
      ];
      return $form;
    }

    // Stash what we need for validation/submit; avoid re-loading the
    // order from user input alone.
    $form_state->set('order_uuid', $order->uuid());
    $form_state->set('order_email', $order->getEmail());

    $form['order_number'] = [
      '#type' => 'item',
      '#title' => $this->t('Order'),
      '#markup' => $this->t('#@number', ['@number' => $order->getOrderNumber() ?: $order->id()]),
    ];

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email address used on the order'),
      '#required' => TRUE,
      '#description' => $this->t('Enter the email address you used at checkout to view this order.'),
    ];

    if ($this->captchaEnabled()) {
      // CAPTCHA element, provided by the contributed CAPTCHA module.
      // '#captcha_type' => 'default' uses whatever challenge type is
      // configured as the site-wide default at
      // /admin/config/people/captcha (image, math, reCAPTCHA, etc.),
      // so this form automatically follows that setting rather than
      // forcing a specific challenge type here.
      $form['captcha'] = [
        '#type' => 'captcha',
        '#captcha_type' => 'default',
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('View order status'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // CAPTCHA validation itself is handled automatically by the captcha
    // module's form process/validate hooks whenever the element is
    // present; we only need to validate the email match here.
    $expected = $form_state->get('order_email');

    if ($expected === NULL) {
      $form_state->setErrorByName('email', $this->t('This order could not be verified.'));
      return;
    }

    $entered = trim(mb_strtolower($form_state->getValue('email')));
    $expected = trim(mb_strtolower($expected));

    if ($entered !== $expected) {
      // Register the failed attempt against this IP for flood
      // control, then give a generic message that doesn't hint at
      // what the correct email is.
      $this->flood->register(self::FLOOD_EVENT, self::FLOOD_WINDOW);
      $form_state->setErrorByName('email', $this->t('We could not verify that email against this order. Please check and try again.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $uuid = $form_state->get('order_uuid');

    if (!$uuid) {
      return;
    }

    // Mark this UUID as verified for the current session so the
    // controller can skip the form on subsequent visits to the link.
    $this->getRequest()->getSession()->set('order_status_url_verified:' . $uuid, TRUE);

    $form_state->setRedirect('order_status_url.guest_order_status', ['uuid' => $uuid]);
  }

}
