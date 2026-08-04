<?php

namespace Drupal\order_status_url\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\order_status_url\Routing\RouteSubscriber;

/**
 * Configures the URL path segment used for the guest order status page.
 */
class SettingsForm extends ConfigFormBase {

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

    $this->config('order_status_url.settings')
      ->set('path', $path)
      ->save();

    // The path is baked into the route at build time, so the router
    // must be rebuilt for the new value to take effect immediately.
    \Drupal::service('router.builder')->rebuild();

    parent::submitForm($form, $form_state);
  }

}
