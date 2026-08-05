<?php

namespace Drupal\order_status_url\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
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
 */
class OrderStatusVerifyForm extends FormBase {

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = new static();
    $instance->moduleHandler = $container->get('module_handler');
    $instance->configFactory = $container->get('config.factory');
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
   * @param \Drupal\commerce_order\Entity\OrderInterface|null $order
   *   The order being looked up, passed in from the controller.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?OrderInterface $order = NULL) {
    if (!$order) {
      $form_state->set('order_uuid', NULL);
      $form_state->set('order_email', NULL);
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
      // Generic message: don't hint at what the correct email is.
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
