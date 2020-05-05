<?php

namespace Drupal\webform_summary\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Mail summary email handler.
 *
 * @WebformHandler(
 *     id = "mail_summary_handler",
 *     label = @Translation("Mail summary Handler"),
 *     category = @Translation("Form Handler"),
 *     description = @Translation("Sends submission data summary to email"),
 *     cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *     results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 * )
 * @package Drupal\wks_custom\Plugin\WebformHandler
 */
class SummaryHandler extends WebformHandlerBase {

  /**
   * {@inheritdoc}
   */
  public function getSummary() {
      // return [
      //   '#settings' => ['settings' => [$this->t('Recipient email address') => $this->configuration['recipient_mail']]],
      //   '#theme' => 'webform_handler_settings_summary',
      // ] + parent::getSummary();
    return [
      'message' => [
        '#markup' => $this->configuration['recipient_mail'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'recipient_mail' => '',
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['general']['recipient_mail'] = [
      '#type' => 'email',
      '#title' => $this->t('Recipient email address'),
      '#description' => $this->t('The email address of the recipient for the summary.'),
      '#default_value' => $this->configuration['recipient_mail'],
      '#required' => TRUE,
      '#maxlength' => 500,
    ];
    $this->elementTokenValidate($form);
    return $this->setSettingsParents($form);
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->hasAnyErrors()) {
      return;
    }
    if (!\Drupal::service('email.validator')->isValid($form_state->getValue('recipient_mail'))) {
      $form_state->setErrorByName('recipient_mail', t('The email address %mail is not valid.', ['%mail' => $value]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $this->applyFormStateToConfiguration($form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    return TRUE;
  }

}
