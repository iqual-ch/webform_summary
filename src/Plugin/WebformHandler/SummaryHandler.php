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
    // Return [
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
      'excluded_elements' => [],
      'metadata' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration) {
    parent::setConfiguration($configuration);

    // Make sure 'default' is converted to '_default'.
    // @see https://www.drupal.org/project/webform/issues/2980470
    // @see webform_update_8131()
    // @todo Webform 8.x-6.x: Remove the below code.
    $default_configuration = $this->defaultConfiguration();
    foreach ($this->configuration as $key => $value) {
      if ($value === 'default'
        && isset($default_configuration[$key])
        && $default_configuration[$key] === static::DEFAULT_VALUE) {
        $this->configuration[$key] = static::DEFAULT_VALUE;
      }
    }

    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['general'] = [
      '#type' => 'details',
      '#title' => $this->t('General settings'),
      '#description' => $this->t(''),
      '#open' => TRUE,
    ];
    $form['general']['recipient_mail'] = [
      '#type' => 'email',
      '#title' => $this->t('Recipient email address'),
      '#description' => $this->t('The email address of the recipient for the summary.'),
      '#default_value' => $this->configuration['recipient_mail'],
      '#required' => TRUE,
      '#maxlength' => 500,
    ];
    if ($_SERVER['REMOTE_ADDR'] == '83.150.28.13') {
      // Elements.
      $form['elements'] = [
        '#type' => 'details',
        '#title' => $this->t('Included email values/markup'),
        '#description' => $this->t('The selected elements will be included in the [webform_submission:values] token. Individual values may still be printed if explicitly specified as a [webform_submission:values:?] in the email body template.'),
        '#open' => $this->configuration['excluded_elements'] ? TRUE : FALSE,
      ];
      $form['elements']['metadata'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Include metadata'),
        '#description' => $this->t('If checked, metadata fields are included.'),
        '#return_value' => TRUE,
        '#default_value' => $this->configuration['metadata'],
      ];
      $form['elements']['excluded_elements'] = [
        '#type' => 'webform_excluded_elements',
        '#exclude_markup' => FALSE,
        '#webform_id' => $this->webform->id(),
        '#default_value' => $this->configuration['excluded_elements'],
      ];
    }

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

    $values = $form_state->getValues();

    foreach ($this->configuration as $name => $value) {
      if (isset($values[$name])) {
        // Convert options array to safe config array to prevent errors.
        // @see https://www.drupal.org/node/2297311
        if (preg_match('/_options$/', $name)) {
          $this->configuration[$name] = WebformOptionsHelper::encodeConfig($values[$name]);
        }
        else {
          $this->configuration[$name] = $values[$name];
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    return TRUE;
  }

}
