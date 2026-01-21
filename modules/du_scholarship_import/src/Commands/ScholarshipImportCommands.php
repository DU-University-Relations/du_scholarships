<?php

namespace Drupal\du_scholarship_import\Commands;

use Drupal\du_scholarship_import\ScholarshipImport;
use Drush\Commands\DrushCommands;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;

/**
 * A Drush commandfile for the event importer.
 */
class ScholarshipImportCommands extends DrushCommands {

  /**
   * The Scholarship Import service.
   *
   * @var \Drupal\du_scholarship_import\ScholarshipImport
   */
  protected $scholarshipImport;

  /**
   * The Queue Factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * Logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs an ScholarshipImportCommands object.
   *
   * @param \Drupal\du_scholarship_import\ScholarshipImport $scholarship_import
   *   The scholarship import service.
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger
   *   The logger factory.
   */
  public function __construct(
    ScholarshipImport $scholarship_import,
    QueueFactory $queue_factory,
    LoggerChannelFactoryInterface $logger) {
    $this->scholarshipImport = $scholarship_import;
    $this->queueFactory = $queue_factory;
    $this->loggerFactory = $logger;
  }

  /**
   * Query the Scholarship API and add results to the queue.
   *
   * @command du:scholarship-import-queue-api
   *
   * @aliases du-siqa,du-scholarship-import-queue-api
   */
  public function scholarshipImportQueueApi() {
    // Logger.
    $logger = $this->loggerFactory->get('du_scholarship_import');

    // Call function to get Scholarships from API Endpoint.
    $scholarships = $this->scholarshipImport->getScholarships();

    // Store API scholarship codes to be used in archiving process.
    $scholarship_api_hash_array = [];

    if (!empty($scholarships)) {
      $queue = $this->queueFactory->get('du_scholarship_import_queue');
      $counter = 0;

      // Loop through each scholarship and add to import queue.
      foreach ($scholarships as $scholarship) {
        if (!empty($scholarship['code']) and !empty($scholarship['name'])) {

          // Create Scholarship API hash for archiving process.
          $scholarshipHash = $this->getHash($scholarship);
          if (!in_array($scholarshipHash, $scholarship_api_hash_array)) {
            $scholarship_api_hash_array[] = $scholarshipHash;
          }

          $queue->createItem($scholarship);
          $counter++;
        }
      }

      // Queue up the imported scholarship api hash array for archiving process.
      $queue2 = $this->queueFactory->get('du_scholarship_archive_queue');
      $queue2->createItem($scholarship_api_hash_array);

      $logger->info(
        'The drush du:scholarship-import-queue-api command was executed and %counter out of %total scholarships were added to the queue.',
        ['%counter' => $counter, '%total' => count($scholarships)]
      );
    }
    else {
      $logger->error('The drush du:scholarship-import-queue-api command was executed and got a response from the API, but failed to parse the data.');
    }
  }

  /**
   * Generates a hash of a Scholarship from the API.
   *
   * This hash will stored in a field on the corresponding node, and will later
   * be used to compare the node to the event and determine whether it has
   * changed and needs to update.
   *
   * @param object $scholarship
   *   Scholarship object as parsed from DU JSON feed with json_decode.
   */
  public function getHash($scholarship) {
    return md5(serialize($scholarship));
  }

}
