<?php

namespace Drupal\webform_summary\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * The configuration form for the webform summary module.
 */
class ConfigurationForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'webform_summary_configuration_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['webform_summary.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('webform_summary.settings');
    $form['webform_submissions_sender'] = [
      '#title' => $this->t('Webform summary sender email'),
      '#type' => 'email',
      '#description' => $this->t('Email from which the webform submissions are sent.'),
      '#required' => TRUE,
      '#element_validate' => ['::validateReturnPath', ['\Drupal\Core\Render\Element\Email', 'validateEmail']],
      '#default_value' => $config->get('webform_submissions_sender'),
    ];
    $form['webform_submissions_email'] = [
      '#title' => $this->t('Webform summary fallback email'),
      '#type' => 'email',
      '#description' => $this->t('Fallback email to which webform submissions without handler should be sent. No fallback is sent when not set.'),
      '#required' => FALSE,
      '#element_validate' => ['::validateReturnPath', ['\Drupal\Core\Render\Element\Email', 'validateEmail']],
      '#default_value' => $config->get('webform_submissions_email'),
    ];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => 'Save',
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('webform_summary.settings')
      ->set('webform_submissions_email', $form_state->getValue('webform_submissions_email'))
      ->set('webform_submissions_sender', $form_state->getValue('webform_submissions_sender'))
      ->save();
  }

  /**
   * {@inheritdoc}
   */
  public function validateReturnPath(array &$element, FormStateInterface $form_state, array &$complete_form) {
    if (!\Drupal::service('email.validator')->isValid($form_state->getValue('webform_submissions_sender'))) {
      $form_state->setErrorByName('webform_submissions_sender', t('The email address %mail is not valid.', ['%mail' => $value]));
    }
  }

}
