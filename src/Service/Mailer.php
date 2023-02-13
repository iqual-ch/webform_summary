<?php

namespace Drupal\webform_summary\Service;

use Drupal\webform\WebformSubmissionExporter;
use Drupal\Core\Mail\MailmanagerInterface;
use Drupal\user\Entity\User;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;

/**
 * This service allows to collect and send submissions of webforms via mail.
 */
class Mailer
{

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

  protected $excludedColumns = [
    'uuid' => 'uuid',
    'token' => 'token',
    'webform_id' => 'webform_id',
  ];

  protected $metaColumns = [
    'serial' => 'serial',
    'sid' => 'sid',
    'uri' => 'uri',
    'completed' => 'completed',
    'changed' => 'changed',
    'in_draft' => 'in_draft',
    'current_page' => 'current_page',
    'remote_addr' => 'remote_addr',
    'langcode' => 'langcode',
    'entity_type' => 'entity_type',
    'entity_id' => 'entity_id',
    'sticky' => 'sticky',
    'notes' => 'notes',
    'uid' => 'uid',
    'locked' => 'locked',
  ];

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
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
  public function __construct(MailmanagerInterface $mailManager, WebformSubmissionExporter $submissionExporter)
  {
    $this->mailManager = $mailManager;
    $this->submissionExporter = $submissionExporter;
    $this->rangeStart = (new \DateTime('today'));
    $this->rangeEnd = (new \DateTime('today'));
  }

  /**
   * Set the range start date.
   *
   * @param array $webformIds
   */
  public function setWebformIds(array $webformIds = NULL)
  {
    $this->webformIds = $webformIds;
  }

  /**
   * Set the range start date.
   *
   * @param \DateTime $startDate
   */
  public function setStartDate(\DateTime $startDate)
  {
    $this->rangeStart = $startDate;
  }

  /**
   * Set the range end date.
   *
   * @param \DateTime $endDate
   */
  public function setEndDate(\DateTime $endDate)
  {
    $this->rangeEnd = $endDate;
  }

  /**
   * Whether to send unhandled webforms to fallback.
   *
   * @param bool $useFallback
   *   A boolean to indicate whether to use the fallback.
   */
  public function useFallback($useFallback = TRUE)
  {
    $this->useFallback = $useFallback;
  }

  /**
   * Set the range start date.
   *
   * @param \DateTime $startDate
   */
  public function setExcludedColums(array $excludedColumns)
  {
    $this->excludedColumns += $excludedColumns;
  }

  /**
   * Run the mailing.
   */
  public function run()
  {
    $webforms = Webform::loadMultiple($this->webformIds);
    $mails = $this->collectFiles($webforms);
    $this->sendMails($mails);
    $this->cleanup($mails);
  }

  /**
   * Collect the files from all webforms.
   *
   * @param array $webforms
   *   The webforms to collect the entries from.
   */
  protected function collectFiles(array $webforms)
  {
    // Setup options.
    $options = $this->submissionExporter->getDefaultExportOptions();
    $options['delimiter'] = ';';
    $options['range_type'] = 'date';
    $options['range_start'] = $this->rangeStart->format('Y-m-d');
    $options['range_end'] = $this->rangeEnd->format('Y-m-d');
    $options['excluded_columns'] = $this->excludedColumns;
    $options['destination'] = 'webform_submissions_export';
    $mails = [];
    $defaultMail = \Drupal::config('webform_summary.settings')->get('webform_submissions_email');
    $useFallback = $this->useFallback;
    if ($useFallback && !empty($defaultMail)) {
      $mails = [$defaultMail => []];
    } else {
      $useFallback = FALSE;
    }

    // Loop through webforms, collect submissions, write files and collect mail info.
    foreach ($webforms as $webform) {
      $webformSubmissions = NULL;
      $this->submissionExporter->setWebform($webform);
      $this->submissionExporter->setExporter($options);
      $query = $this->submissionExporter->getQuery();
      $query->addMetaData('account', User::load(1));
      $sids = $query->execute();
      // Only write and add mail info if there are submissions in range.
      if (count($sids) > 0) {
        // If there are summary handlers on the webform, use the email address from the handler.
        $handlers = $webform->getHandlers();
        $fallback = TRUE;
        foreach ($handlers as $handler) {
          if ($handler->getPluginId() == 'mail_summary_handler' && $handler->getStatus()) {
            $configuration = $handler->getConfiguration();
            if (!empty($configuration['settings']) && !empty($configuration['settings']['recipient_mail'])) {
              $options['excluded_columns'] = $this->getExcludedColumns($webform, $configuration);
              $email = $configuration['settings']['recipient_mail'];
              if ($webformSubmissions == NULL) {
                $webformSubmissions = WebformSubmission::loadMultiple($sids);
              }
              $entry = $this->writeFile($webform, $webformSubmissions, $options, $configuration['settings']['recipient_mail']);
              if ($entry != NULL) {
                $mails[$email][] = $entry;
              }
              $options['excluded_columns'] = $this->excludedColumns;
              $fallback = FALSE;
            }
          }
        }

        // Fall back to the default email if there is no handler.
        if ($useFallback && $fallback) {
          if ($webformSubmissions == NULL) {
            $webformSubmissions = WebformSubmission::loadMultiple($sids);
          }
          $entry = $this->writeFile($webform, $webformSubmissions, $options, $defaultMail);
          if ($entry != NULL) {
            $mails[$defaultMail][] = $entry;
          }
        }
      }
    }
    return $mails;
  }

  /**
   * Undocumented function.
   *
   * @param [type] $webform
   * @param [type] $webformSubmissions
   * @param [type] $options
   * @param [type] $email
   *
   * @return void
   */
  protected function writeFile($webform, $webformSubmissions, $options, $email)
  {
    $file = $webform->id() . '.csv';
    $this->submissionExporter->setExporter($options);
    $this->submissionExporter->writeHeader();
    $this->submissionExporter->writeRecords($webformSubmissions);
    // Make sure the file exists.
    if (file_exists('/tmp/' . $file)) {
      $personalFilename = '/tmp/' . $webform->id() . '_' . $email . '.csv';
      rename('/tmp/' . $file, $personalFilename);
      return ['path' => $personalFilename, 'name' => $file];
    }
    return NULL;
  }

  /**
   * Send all mails collected across webforms.
   *
   * @param array $mails
   *   The mail info.
   */
  protected function sendMails($mails)
  {
    foreach ($mails as $recipient => $files) {
      $params = ['attachments' => [], 'subject' => 'Webform summary'];
      foreach ($files as $delta => $fileInfo) {
        $fileContent = iconv("UTF-8", "ISO-8859-1//IGNORE", file_get_contents($fileInfo['path']));
        if (!empty($fileContent)) {
          $params['attachments'][] = [
            'filecontent' => $fileContent,
            'filename' => $fileInfo['name'],
            'filemime' => 'text/csv',
          ];
        }
      }
      $mailerDisabled = \Drupal::config('webform_summary.settings')->get('webform_submissions_disable');
      if ($mailerDisabled) {
        return;
      }
      if (!empty($params['attachments'])) {
        $fileList = implode(', ', array_column($files, 'name'));
        if ($this->mailManager->mail('webform_summary', 'webform_summary_csv', $recipient, 'en', $params)) {
          \Drupal::logger('webform_summary')->notice('Sent webform summaries to ' . $recipient . '. File list: ' . $fileList);
        } else {
          \Drupal::logger('webform_summary')->warn('Could not sent webform summaries to ' . $recipient . '. File list: ' . $fileList);
        }
      } else {
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
  protected function cleanup(array $mails)
  {
    foreach ($mails as $recipient => $files) {
      foreach ($files as $delta => $fileInfo) {
        if (file_exists($fileInfo['path'])) {
          unlink($fileInfo['path']);
        }
      }
    }
  }

  /**
   *
   */
  protected function getExcludedColumns($webform, $handlerConfiguration)
  {
    $excludedColumns = $this->excludedColumns + $handlerConfiguration['settings']['excluded_elements'];
    if (!$handlerConfiguration['settings']['metadata']) {
      $excludedColumns += $this->metaColumns;
    }
    return $excludedColumns;
  }
}
