<?php

declare(strict_types=1);

namespace Drupal\Tests\webform_summary\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\webform_summary\Form\ConfigurationForm;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the webform summary configuration form.
 *
 * @group webform_summary
 */
#[Group('webform_summary')]
class ConfigurationFormKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'path',
    'path_alias',
    'field',
    'filter',
    'datetime',
    'webform',
    'webform_summary',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('user');
    $this->installEntitySchema('webform_submission');
    $this->installSchema('webform', ['webform']);
    $this->installConfig(['system', 'webform', 'webform_summary']);
  }

  /**
   * Tests that the form can be built through the container.
   */
  public function testBuildForm(): void {
    $form = $this->container->get('form_builder')->getForm(ConfigurationForm::class);
    $this->assertArrayHasKey('webform_submissions_sender', $form);
    $this->assertArrayHasKey('webform_submissions_email', $form);
  }

  /**
   * Tests that valid values are saved.
   */
  public function testSubmitValidValues(): void {
    $form_state = $this->buildFormState([
      'webform_submissions_sender' => 'sender@example.com',
      'webform_submissions_email' => 'fallback@example.com',
    ]);
    $this->container->get('form_builder')->submitForm(ConfigurationForm::class, $form_state);

    $this->assertSame([], $form_state->getErrors());
    $config = $this->config('webform_summary.settings');
    $this->assertSame('sender@example.com', $config->get('webform_submissions_sender'));
    $this->assertSame('fallback@example.com', $config->get('webform_submissions_email'));
  }

  /**
   * Tests that an invalid fallback email is reported on its own element.
   */
  public function testSubmitInvalidFallbackEmail(): void {
    $form_state = $this->buildFormState([
      'webform_submissions_sender' => 'sender@example.com',
      'webform_submissions_email' => 'not-an-email',
    ]);
    $this->container->get('form_builder')->submitForm(ConfigurationForm::class, $form_state);

    $errors = $form_state->getErrors();
    $this->assertArrayHasKey('webform_submissions_email', $errors);
    $this->assertArrayNotHasKey('webform_submissions_sender', $errors);
  }

  /**
   * Builds a form state with valid defaults and the given values.
   *
   * @param array $values
   *   Values overriding the defaults.
   *
   * @return \Drupal\Core\Form\FormState
   *   The form state.
   */
  protected function buildFormState(array $values): FormState {
    return (new FormState())->setValues($values + [
      'webform_submissions_subject' => 'Webform summary',
      'webform_submissions_body' => '',
      'webform_close_send_data' => 1,
      'webform_submissions_disable' => 0,
      'op' => 'Save',
    ]);
  }

}
