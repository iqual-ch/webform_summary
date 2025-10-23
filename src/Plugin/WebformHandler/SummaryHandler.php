<?php

namespace Drupal\webform_summary\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\Utility\WebformOptionsHelper;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
   * The mail validator service.
   *
   * @var \Drupal\Component\Utility\EmailValidatorInterface
   */
  protected $emailValidator;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);

    $instance->loggerFactory = $container->get('logger.factory');
    $instance->configFactory = $container->get('config.factory');
    $instance->renderer = $container->get('renderer');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->conditionsValidator = $container->get('webform_submission.conditions_validator');
    $instance->tokenManager = $container->get('webform.token_manager');
    $instance->emailValidator = $container->get('email.validator');

    $instance->setConfiguration($configuration);

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary() {
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
   * {@inheritDoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['general'] = [
      '#type' => 'details',
      '#title' => $this->t('General settings'),
      '#description' => $this->t('General settings for the email summary handler.'),
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
    if (!$this->emailValidator->isValid($form_state->getValue('recipient_mail'))) {
      $form_state->setErrorByName('recipient_mail', $this->t('The email address %mail is not valid.', ['%mail' => $form_state->getValue('recipient_mail')]));
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
