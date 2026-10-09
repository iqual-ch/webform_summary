<?php

declare(strict_types=1);

namespace Drupal\Tests\webform_summary\Kernel;

use Drupal\Core\Test\AssertMailTrait;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;
use Drupal\webform\WebformInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that webform summaries are built and mailed with a CSV attachment.
 *
 * These tests run the real webform submission exporter, so they catch changes
 * in the webform module that break the summary files (e.g. webform 6.3.1
 * moving export files into a "webform" subdirectory of the temp directory,
 * which silently stopped all summaries).
 *
 * @group webform_summary
 */
#[Group('webform_summary')]
class SummaryMailerKernelTest extends KernelTestBase {

  use AssertMailTrait;
  use UserCreationTrait;

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
    // Keep export files of each test in its own temp directory.
    $this->setSetting('file_temp_path', $this->siteDirectory . '/temp');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('user');
    $this->installEntitySchema('webform_submission');
    $this->installSchema('webform', ['webform']);
    $this->installConfig(['system', 'webform', 'webform_summary']);

    // The mailer runs the submission query as user 1.
    $this->createUser([], 'admin', TRUE, ['uid' => 1]);

    $this->config('system.mail')
      ->set('interface.default', 'test_mail_collector')
      ->save();
    $this->config('webform_summary.settings')
      ->set('webform_submissions_sender', 'sender@example.com')
      ->set('webform_submissions_subject', 'Test summary')
      ->set('webform_submissions_disable', FALSE)
      ->save();
  }

  /**
   * Tests that every summary handler recipient gets the CSV attachment.
   *
   * One recipient contains a "/", which is valid in an email address but not
   * in a filename.
   */
  public function testSummaryIsSentToEachHandlerRecipient(): void {
    $webform = $this->createWebform('summary_test', [
      'first@example.com',
      'sales/ops@example.com',
    ]);
    $this->createSubmission($webform, 'Alice');
    $this->createSubmission($webform, 'Bob');

    $this->runMailer([$webform->id()]);

    $mails = $this->getMails(['key' => 'webform_summary_csv']);
    $this->assertSame(
      ['first@example.com', 'sales/ops@example.com'],
      $this->sortedRecipients($mails),
    );
    foreach ($mails as $mail) {
      $this->assertSame('sender@example.com', $mail['from']);
      $this->assertSame('Test summary', $mail['subject']);
      $this->assertCount(1, $mail['params']['attachments']);
      $attachment = $mail['params']['attachments'][0];
      $this->assertSame('summary_test.csv', $attachment['filename']);
      $this->assertSame('text/csv', $attachment['filemime']);
      $this->assertStringContainsString('Alice', $attachment['filecontent']);
      $this->assertStringContainsString('Bob', $attachment['filecontent']);
    }
    $this->assertExportDirectoryIsEmpty();
  }

  /**
   * Tests that webforms without a summary handler use the fallback recipient.
   */
  public function testSummaryIsSentToFallbackRecipient(): void {
    $this->config('webform_summary.settings')
      ->set('webform_submissions_email', 'fallback@example.com')
      ->save();
    $webform = $this->createWebform('fallback_test');
    $this->createSubmission($webform, 'Carol');

    $this->runMailer([$webform->id()]);

    $mails = $this->getMails(['key' => 'webform_summary_csv']);
    $this->assertSame(['fallback@example.com'], $this->sortedRecipients($mails));
    $attachment = $mails[0]['params']['attachments'][0];
    $this->assertSame('fallback_test.csv', $attachment['filename']);
    $this->assertStringContainsString('Carol', $attachment['filecontent']);
    $this->assertExportDirectoryIsEmpty();
  }

  /**
   * Tests that no summary is sent for a webform without submissions.
   */
  public function testNoSummaryIsSentWithoutSubmissions(): void {
    $webform = $this->createWebform('empty_test', ['first@example.com']);

    $this->runMailer([$webform->id()]);

    $this->assertEmpty($this->getMails(['key' => 'webform_summary_csv']));
  }

  /**
   * Tests that no summary is sent when sending is globally disabled.
   */
  public function testNoSummaryIsSentWhenDisabled(): void {
    $this->config('webform_summary.settings')
      ->set('webform_submissions_disable', TRUE)
      ->save();
    $webform = $this->createWebform('disabled_test', ['first@example.com']);
    $this->createSubmission($webform, 'Dave');

    $this->runMailer([$webform->id()]);

    $this->assertEmpty($this->getMails(['key' => 'webform_summary_csv']));
  }

  /**
   * Tests that cron sends the summary of open webforms once per day.
   */
  public function testCronSendsSummaryOncePerDay(): void {
    $webform = $this->createWebform('cron_test', ['first@example.com']);
    $this->createSubmission($webform, 'Erin');

    webform_summary_cron();
    $this->assertCount(1, $this->getMails(['key' => 'webform_summary_csv']));

    // A second cron run on the same day must not send again.
    webform_summary_cron();
    $this->assertCount(1, $this->getMails(['key' => 'webform_summary_csv']));
  }

  /**
   * Creates an open webform with summary handlers for the given recipients.
   *
   * @param string $id
   *   The webform ID.
   * @param string[] $recipients
   *   The recipient of each summary handler to add.
   *
   * @return \Drupal\webform\WebformInterface
   *   The webform.
   */
  protected function createWebform(string $id, array $recipients = []): WebformInterface {
    $webform = Webform::create([
      'id' => $id,
      'title' => $id,
      'elements' => "name:\n  '#type': textfield\n  '#title': Name",
    ]);
    $handlerManager = $this->container->get('plugin.manager.webform.handler');
    foreach ($recipients as $delta => $recipient) {
      $handler = $handlerManager->createInstance('mail_summary_handler', [
        'handler_id' => 'mail_summary_handler_' . $delta,
        'status' => TRUE,
        'settings' => [
          'recipient_mail' => $recipient,
          'excluded_elements' => [],
          'metadata' => FALSE,
        ],
      ]);
      $webform->addWebformHandler($handler);
    }
    $webform->save();
    return $webform;
  }

  /**
   * Creates a completed submission for the given webform.
   *
   * @param \Drupal\webform\WebformInterface $webform
   *   The webform.
   * @param string $name
   *   The value of the name element.
   */
  protected function createSubmission(WebformInterface $webform, string $name): void {
    WebformSubmission::create([
      'webform_id' => $webform->id(),
      'data' => ['name' => $name],
    ])->save();
  }

  /**
   * Runs the summary mailer the same way the module does.
   *
   * @param string[] $webformIds
   *   The webform IDs to send summaries for.
   */
  protected function runMailer(array $webformIds): void {
    _webform_summary_send_data($webformIds);
  }

  /**
   * Returns the sorted recipients of the given mails.
   *
   * @param array $mails
   *   The collected mails.
   *
   * @return string[]
   *   The recipients.
   */
  protected function sortedRecipients(array $mails): array {
    $recipients = array_column($mails, 'to');
    sort($recipients);
    return $recipients;
  }

  /**
   * Asserts that no export files are left behind in the temp directory.
   */
  protected function assertExportDirectoryIsEmpty(): void {
    $directory = $this->container->get('webform_submission.exporter')->getFileTempDirectory();
    $this->assertSame([], array_values(preg_grep('/\.csv$/', scandir($directory))));
  }

}
