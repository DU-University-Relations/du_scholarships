<?php

namespace Drupal\du_scholarship_import\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class RemoveTimestamps.
 *
 * @package Drupal\du_scholarship_import\Form
 */
class RemoveTimestamps extends FormBase {

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Logger channel.
   *
   * @var Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a \Drupal\du_scholarship_import\Form\RemoveTimestamps object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, Connection $database, LoggerInterface $logger) {
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('database'),
      $container->get('logger.factory')->get('du_scholarship_import')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'du_scholarship_import_remove_timestamps';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $node_storage = $this->entityTypeManager->getStorage('node');
    $taxonomy_storage = $this->entityTypeManager->getStorage('taxonomy_term');

    $form['note'] = [
      '#type' => 'markup',
      '#markup' => $this->t('Note: After performing this action you will need to clear cache to finalize the removal.'),
    ];

    $form['remove_options'] = [
      '#type' => 'select',
      '#title' => $this->t('Remove Timestamps From'),
      '#description' => $this->t('Choose which scholarships that you want to remove timestamps from.'),
      '#options' => [
        'all' => $this->t('All Scholarships'),
        'single' => $this->t('Single Scholarship'),
      ],
    ];

    $form['scholarship_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Scholarship Code'),
      '#description' => $this->t('Enter the Scholarship Code of the scholarship where you want to delete the timestamp.'),
      '#size' => 10,
      '#states' => [
        'visible' => [
          ':input[name="remove_options"]' => [
            'value' => 'single',
          ],
        ],
      ],
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    // Check that a valid scholarship code is provided if choosing a single scholarship.
    if ($values['remove_options'] == 'single') {
      if (empty($values['scholarship_code'])) {
        $form_state->setErrorByName('scholarship_code', $this->t('The scholarship code must be provided.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $node_storage = $this->entityTypeManager->getStorage('node');

    // Remove the timestamps.
    if ($values['remove_options'] == 'all') {
      $result = $node_storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'scholarship')
        ->execute();

      if (!empty($result)) {
        $this->database
          ->delete('node__field_api_update_stamp')
          ->condition('entity_id', $result, 'IN')
          ->execute();
        $this->database
          ->delete('node_revision__field_api_update_stamp')
          ->condition('entity_id', $result, 'IN')
          ->execute();
        $message = $this->t('Timestamps were deleted from all scholarships.');
      }
      else {
        $message = $this->t('There were no timestamps to delete.');
      }
    }
    elseif ($values['remove_options'] == 'single') {
      $result = $node_storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'scholarship')
        ->condition('field_scholarship_code', $values['scholarship_code'])
        ->execute();

      if (!empty($result)) {
        $this->database
          ->delete('node__field_api_update_stamp')
          ->condition('entity_id', $result, 'IN')
          ->execute();
        $this->database
          ->delete('node_revision__field_api_update_stamp')
          ->condition('entity_id', $result, 'IN')
          ->execute();
        $message = $this->t('Timestamps were deleted from the scholarship with scholarship code %scholarship_code.', ['%scholarship_code' => $values['scholarship_code']]);
      }
      else {
        $message = $this->t('There are no scholarships with scholarship code %scholarship_code, so no timestamps were deleted.', ['%scholarship_code' => $values['scholarship_code']]);
      }
    }

    if (!empty($message)) {
      $this->logger->notice($message);
      $this->messenger()->addMessage($message);
    }
  }

}
