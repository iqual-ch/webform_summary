<?php

namespace Drupal\webform_summary\Form;

use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Render\Element\Email;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The configuration form for the webform summary module.
 */
class ConfigurationForm extends ConfigFormBase {

  /**
   * The email validator.
   *
   * @var \Drupal\Component\Utility\EmailValidatorInterface
   */
  protected $emailValidator;

  /**
   * Constructs a Drupal\webform_summary\Form\ConfigurationForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Component\Utility\EmailValidatorInterface $email_validator
   *   The email validator.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EmailValidatorInterface $email_validator) {
    parent::__construct($config_factory);
    $this->emailValidator = $email_validator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('email.validator')
    );
  }

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
      '#element_validate' => [
        '::validateReturnPath',
        [Email::class, 'validateEmail'],
      ],
      '#default_value' => $config->get('webform_submissions_sender'),
    ];
    $form['webform_submissions_subject'] = [
      '#title' => $this->t('Webform summary subject line'),
      '#type' => 'textfield',
      '#description' => $this->t('Subject line for the daily summary mail.'),
      '#required' => TRUE,
      '#default_value' => $config->get('webform_submissions_subject'),
    ];
    $form['webform_submissions_body'] = [
      '#title' => $this->t('Webform summary body content'),
      '#type' => 'textarea',
      '#description' => $this->t('Body content for the daily summary mail.'),
      '#required' => FALSE,
      '#default_value' => $config->get('webform_submissions_body'),
    ];
    $form['webform_submissions_email'] = [
      '#title' => $this->t('Webform summary fallback email'),
      '#type' => 'email',
      '#description' => $this->t('Fallback email to which webform submissions without handler should be sent. No fallback is sent when not set.'),
      '#required' => FALSE,
      '#element_validate' => [
        '::validateReturnPath',
        [Email::class, 'validateEmail'],
      ],
      '#default_value' => $config->get('webform_submissions_email'),
    ];
    $form['webform_close_send_data'] = [
      '#title' => $this->t('Send data when closing, deleting or archiving a webform'),
      '#type' => 'checkbox',
      '#description' => $this->t('Will send the data every time a webform is closed or archived or when it is deleted.'),
      '#required' => TRUE,
      '#default_value' => $config->get('webform_close_send_data'),
    ];
    $form['webform_submissions_disable'] = [
      '#title' => $this->t('Globally disable webform summary'),
      '#type' => 'checkbox',
      '#description' => $this->t('Globally disable webform summary'),
      '#required' => FALSE,
      '#default_value' => $config->get('webform_submissions_disable'),
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
      ->set('webform_submissions_subject', $form_state->getValue('webform_submissions_subject'))
      ->set('webform_submissions_body', $form_state->getValue('webform_submissions_body'))
      ->set('webform_close_send_data', $form_state->getValue('webform_close_send_data'))
      ->set('webform_submissions_disable', $form_state->getValue('webform_submissions_disable'))
      ->save();
  }

  /**
   * {@inheritdoc}
   */
  public function validateReturnPath(array &$element, FormStateInterface $form_state, array &$complete_form) {
    if (!$this->emailValidator->isValid($form_state->getValue('webform_submissions_sender'))) {
      $form_state->setErrorByName('webform_submissions_sender', t('The email address %mail is not valid.', ['%mail' => $value]));
    }
  }

}
