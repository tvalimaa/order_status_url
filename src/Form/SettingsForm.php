<?php

namespace Drupal\order_status_url\Form;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteBuilderInterface;
use Drupal\order_status_url\Routing\RouteSubscriber;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures the URL path segment and CAPTCHA behavior for the order
 * status lookup page.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The module handler, used to detect whether captcha is available.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The route builder, used to rebuild routes after the path changes.
   *
   * @var \Drupal\Core\Routing\RouteBuilderInterface
   */
  protected $routeBuilder;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    /** @var static $instance */
    $instance = parent::create($container);
    $instance->moduleHandler = $container->get('module_handler');
    $instance->routeBuilder = $container->get('router.builder');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['order_status_url.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'order_status_url_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('order_status_url.settings');

    $form['path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('URL path segment'),
      '#description' => $this->t(
        'The path segment used for the guest order status lookup page. For example, entering "@default" produces the URL /@default/{uuid}. Leave as the default unless you have a reason to change it. Do not include leading or trailing slashes.',
        ['@default' => RouteSubscriber::DEFAULT_PATH]
      ),
      '#default_value' => $config->get('path') ?: RouteSubscriber::DEFAULT_PATH,
      '#required' => TRUE,
      '#size' => 40,
    ];

    $captcha_available = $this->moduleHandler->moduleExists('captcha');

    if ($captcha_available) {
      $form['captcha_enabled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Require CAPTCHA on the email verification form'),
        '#description' => $this->t(
          'Uses the challenge type configured at the <a href=":url">CAPTCHA settings page</a>. Uncheck to skip the CAPTCHA challenge and only require an email match.',
          [':url' => '/admin/config/people/captcha']
        ),
        '#default_value' => (bool) $config->get('captcha_enabled'),
      ];
    }
    else {
      $form['captcha_unavailable'] = [
        '#type' => 'item',
        '#title' => $this->t('CAPTCHA protection'),
        '#markup' => $this->t('The CAPTCHA module is not installed, so the verification form only checks the email address. Install and enable <a href=":url">CAPTCHA</a> to add a challenge here.', [
          ':url' => 'https://www.drupal.org/project/captcha',
        ]),
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $path = trim($form_state->getValue('path'), '/');

    if ($path === '') {
      $form_state->setErrorByName('path', $this->t('The path may not be empty.'));
      return;
    }

    if (!preg_match('/^[a-z0-9\-\/]+$/i', $path)) {
      $form_state->setErrorByName('path', $this->t('The path may only contain letters, numbers, hyphens and slashes.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $path = trim($form_state->getValue('path'), '/');

    $config = $this->config('order_status_url.settings')
      ->set('path', $path);

    // Only persist the captcha_enabled value if the field was actually
    // shown (i.e. the captcha module is installed); otherwise leave
    // whatever is already stored untouched.
    if ($this->moduleHandler->moduleExists('captcha')) {
      $config->set('captcha_enabled', (bool) $form_state->getValue('captcha_enabled'));
    }

    $config->save();

    // The path is baked into the route at build time, so the router
    // must be rebuilt for the new value to take effect immediately.
    $this->routeBuilder->rebuild();

    parent::submitForm($form, $form_state);
  }

}
