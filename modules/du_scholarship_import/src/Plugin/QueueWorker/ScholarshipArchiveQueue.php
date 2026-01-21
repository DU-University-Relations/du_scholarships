<?php

namespace Drupal\du_scholarship_import\Plugin\QueueWorker;

use Drupal\content_lock\ContentLock\ContentLock;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\QueueFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Remove drupal scholarships not in imported scholarship code list.
 *
 * @QueueWorker(
 *   id = "du_scholarship_archive_queue",
 *   title = @Translation("Scholarship Archive Queue"),
 *   cron = {"time" = 30}
 * )
 */
class ScholarshipArchiveQueue extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

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
   * Content lock service.
   *
   * @var \Drupal\content_lock\ContentLock\ContentLock
   */
  protected $lockService;

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity manager.
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger
   *   The logger factory.
   * @param \Drupal\content_lock\ContentLock\ContentLock $lock_service
   *   Content lock service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    QueueFactory $queue_factory,
    LoggerChannelFactoryInterface $logger,
    ContentLock $lock_service
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->queueFactory = $queue_factory;
    $this->loggerFactory = $logger;
    $this->lockService = $lock_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('queue'),
      $container->get('logger.factory'),
      $container->get('content_lock')
    );
  }

  /**
   * {@inheritdoc}
   *
   * Archive the scholarships on Drupal Site that are not on the new list of imported scholarship codes.
   */
  public function processItem($scholarships_hash_array) {
    // Logger.
    $logger = $this->loggerFactory->get('du_scholarship_import');

    // Fail safe condition: There are more than 10 scholarships imported.
    if (!empty($scholarships_hash_array) and count($scholarships_hash_array) > 10) {

      $node_storage = $this->entityTypeManager->getStorage('node');

      // Find all current active/published drupal scholarships.
      $query = $node_storage->getQuery()
        ->condition('type', 'scholarship')
        ->condition('field_scholarship_api_hash', $scholarships_hash_array, 'NOT IN')
        ->condition('status', '1');
      $nids = $query->accessCheck(TRUE)->execute();
      $scholarship_nodes = $node_storage->loadMultiple($nids);

      if (!empty($scholarship_nodes)) {
        $archived_scholarship_counter = 0;
        foreach ($scholarship_nodes as $node) {
          // Archive this scholarship and save.
          $this->lockService->release($node->id(), $node->language);
          $node->setPublished(FALSE);
          $node->set('moderation_state', 'archived');
          $node->save();

          $archived_scholarship_counter++;

          $logger->info(
            'The %title (node id %nid) scholarship has been archived.',
            ['%title' => $node->getTitle(), '%nid' => $node->id()]
          );
        }

        if ($archived_scholarship_counter > 0) {
          $logger->info(
            'The drush du:scholarship-archive-queue command was executed and %num scholarships were archived.',
            ['%num' => $archived_scholarship_counter]
          );
        }
        else {
          $logger->info('The drush du:scholarship-archive-queue command was executed but there were no scholarships to archive.');
        }
      }
      else {
        $logger->info('The drush du:scholarship-archive-queue command was executed but there were no drupal site scholarships found to archive.');
      }
    }
    else {
      $logger->info(
        'The drush du:scholarship-archive-queue command was executed however only %num scholarships were imported so archiving process did not proceed. Please inform MarComm developers or Financial Aid.',
        ['%num' => count($scholarships_hash_array)]
      );
    }
  }

}
