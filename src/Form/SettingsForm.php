<?php

/**
 * @file
 * Contains \Drupal\webformgithubbridge\Form\SettingsForm.
 */

namespace Drupal\webformgithubbridge\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure webformgithubbridge settings for this site.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'webformgithubbridge_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['webformgithubbridge.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('webformgithubbridge.settings');

    $form['webformgithubbridge_gitlabtoken'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Gitlab webhook token'),
      '#default_value' => $config->get('webformgithubbridge.gitlabtoken'),
      '#description' => $this->t('This is arbitrary just has to match the token used in the webhook at Gitlab. To avoid abuse of this module.'),
    );

    $form['webformgithubbridge_username'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Github username'),
      '#default_value' => $config->get('webformgithubbridge.username'),
    );

    $form['webformgithubbridge_password'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Github access token'),
      '#default_value' => $config->get('webformgithubbridge.password'),
    );

    $form['webformgithubbridge_verifyssl'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Verify SSL'),
      '#default_value' => $config->get('webformgithubbridge.verifyssl'),
      '#description' => $this->t('Useful to turn off for local testing'),
      '#return_value' => 1,
    );

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('webformgithubbridge.settings')
      ->set('webformgithubbridge.gitlabtoken', $form_state->getValue('webformgithubbridge_gitlabtoken'))
      ->set('webformgithubbridge.username', $form_state->getValue('webformgithubbridge_username'))
      ->set('webformgithubbridge.password', $form_state->getValue('webformgithubbridge_password'))
      ->set('webformgithubbridge.verifyssl', $form_state->getValue('webformgithubbridge_verifyssl'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
