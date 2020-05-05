<?php

namespace Drupal\webform_summary\Service;

use Drupal\webform\WebformSubmissionExporter;
use Drupal\Core\Mail\MailmanagerInterface;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;

/**
 * This service allows to collect and send submissions of webforms via mail.
 */
class Mailer {

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailmanagerInterface
   */
  protected $mailManager = NULL;

  /**
   * The webform submission exporter.
   *
   * @var \Drupal\webform\WebformSubmissionExporter
   */
  protected $submissionExporter = NULL;

  /**
   * The range start date.
   *
   * @var \DateTime
   */
  protected $rangeStart = NULL;

  /**
   * The range end date.
   *
   * @var \DateTime
   */
  protected $rangeEnd = NULL;

  /**
   * The webforms to export the data from.
   *
   * @var array
   */
  protected $webformIds = NULL;

  /**
   * Whether to send unhandled webforms to the fallback.
   *
   * @var bool
   */
  protected $useFallback = TRUE;

  protected $time = 0;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
          $container->get('plugin.manager.mail'),
          $container->get('webform_submission.exporter')
      );
  }

  /**
   * Construct a new WebformSummaryMailer.
   *
   * @param \Drupal\Core\Mail\MailmanagerInterface $mailManager
   * @param \Drupal\webform\WebformSubmissionExporter $submissionExporter
   */
  public function __construct(MailmanagerInterface $mailManager, WebformSubmissionExporter $submissionExporter) {
    $this->mailManager = $mailManager;
    $this->submissionExporter = $submissionExporter;
    $this->rangeStart = (new \DateTime('today'));
    $this->rangeEnd = (new \DateTime('today'));
    $this->time = time();
  }

  /**
   * Set the range start date.
   *
   * @param array $webformIds
   */
  public function setWebformIds(array $webformIds = NULL) {
    $this->webformIds = $webformIds;
  }

  /**
   * Set the range start date.
   *
   * @param \DateTime $startDate
   */
  public function setStartDate(\DateTime $startDate) {
    $this->rangeStart = $startDate;
  }

  /**
   * Set the range end date.
   *
   * @param \DateTime $endDate
   */
  public function setEndDate(\DateTime $endDate) {
    $this->rangeEnd = $endDate;
  }

  /**
   * Whether to send unhandled webforms to fallback.
   *
   * @param bool $useFallback
   *   A boolean to indicate whether to use the fallback.
   */
  public function useFallback($useFallback = TRUE) {
    $this->useFallback = $useFallback;
  }

  /**
   * Run the mailing.
   */
  public function run() {
    $webforms = Webform::loadMultiple($this->webformIds);
    $mails = $this->collectFiles($webforms);
    $this->sendMails($mails);
    $this->cleanup($webforms);
  }

  /**
   * Collect the files from all webforms.
   *
   * @param array $webforms
   *   The webforms to collect the entries from.
   */
  protected function collectFiles(array $webforms) {
    // Setup options.
    $defaultOptions = $this->submissionExporter->getDefaultExportOptions();
    $defaultOptions['delimiter'] = ';';
    $defaultOptions['range_type'] = 'date';
    $defaultOptions['range_start'] = $this->rangeStart->format('Y-m-d');
    $defaultOptions['range_end'] = $this->rangeEnd->format('Y-m-d');
    $defaultOptions['destination'] = 'webform_submissions_export';
    $mails = [];
    $defaultMail = \Drupal::config('webform_summary.settings')->get('webform_submissions_email');
    if ($this->useFallback) {
      $defaultMail = \Drupal::config('webform_summary.settings')->get('webform_submissions_email');
      $mails = [$defaultMail => []];
    }

    // Loop through webforms, collect submissions, write files and collect mail info.
    foreach ($webforms as $webform) {

      if (!$this->useFallback) {
        $hasHandler = FALSE;
        $handlers = $webform->getHandlers();
        foreach ($handlers as $handler) {
          if ($handler->getPluginId() == 'mail_summary_handler' && $handler->getStatus()) {
            $configuration = $handler->getConfiguration();
            if (!empty($configuration['settings']) && !empty($configuration['settings']['recipient_mail'])) {
              $hasHandler = TRUE;
            }
          }
        }
        if (!$hasHandler && FALSE) {
          continue;
        }
      }

      $this->submissionExporter->setWebform($webform);
      $this->submissionExporter->setExporter($defaultOptions);
      $this->submissionExporter->writeHeader();
      $query = $this->submissionExporter->getQuery();
      $sids = $query->execute();

      $webformSubmissions = WebformSubmission::loadMultiple($sids);

      // Only write and add mail info if there are submissions in range.
      if (count($webformSubmissions) > 0) {
        $this->submissionExporter->writeRecords($webformSubmissions);
        $file = $webform->id() . '.csv';

        // Make sure the file exists.
        if (file_exists('/tmp/' . $file)) {
          // If there are summary handlers on the webform, use the email address from the handler.
          $handlers = $webform->getHandlers();
          $fallback = TRUE;
          foreach ($handlers as $handler) {
            if ($handler->getPluginId() == 'mail_summary_handler' && $handler->getStatus()) {
              $configuration = $handler->getConfiguration();
              if (!empty($configuration['settings']) && !empty($configuration['settings']['recipient_mail'])) {
                $mails[$configuration['settings']['recipient_mail']][] = $file;
                $fallback = FALSE;
              }
            }
          }

          // Fall back to the default email if there is no handler.
          if ($this->useFallback && $fallback) {
            $mails[$defaultMail][] = $file;
          }
        }
      }
    }
    echo (time() - $this->time);
    return $mails;
  }

  /**
   * Send all mails collected across webforms.
   *
   * @param array $mails
   *   The mail info.
   */
  protected function sendMails($mails) {
    print_r($mails);
    return;
    foreach ($mails as $recipient => $files) {
      $params = ['attachments' => [], 'subject' => 'Webform summary'];
      foreach ($files as $delta => $file) {
        $fileContent = iconv("UTF-8", "ISO-8859-1//IGNORE", file_get_contents('/tmp/' . $file));
        if (!empty($fileContent)) {
          $params['attachments'][] = [
            'filecontent' => $fileContent,
            'filename' => $file,
            'filemime' => 'text/csv',
          ];
        }
      }

      if (!empty($params['attachments'])) {
        $fileList = implode(', ', $files);
        if ($this->mailManager->mail('webform_summary', 'webform_summary_csv', $recipient, 'en', $params)) {
          \Drupal::logger('webform_summary')->notice('Sent webform summaries to ' . $recipient . '. File list: ' . $fileList);
        }
        else {
          \Drupal::logger('webform_summary')->warn('Could not sent webform summaries to ' . $recipient . '. File list: ' . $fileList);
        }
      }
      else {
        \Drupal::logger('webform_summary')->notice('Did not sent webform summaries to ' . $recipient . '. (no attachments)');
      }
    }
  }

  /**
   * Clean up files in tmp.
   *
   * @param array $webforms
   *   The webforms for which to clean the files up.
   */
  protected function cleanup(array $webforms) {
    foreach ($webforms as $webform) {
      if (file_exists('/tmp/' . $webform->id() . '.csv')) {
        unlink('/tmp/' . $webform->id() . '.csv');
      }
    }
  }

}
