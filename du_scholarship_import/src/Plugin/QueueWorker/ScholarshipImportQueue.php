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
 * Process list of scholarships to be imported.
 *
 * @QueueWorker(
 *   id = "du_scholarship_import_queue",
 *   title = @Translation("Scholarship Import Queue"),
 *   cron = {"time" = 30}
 * )
 */
class ScholarshipImportQueue extends QueueWorkerBase implements ContainerFactoryPluginInterface {

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
   * Function takes an array and clean the values.
   *
   * @param array $array
   *   Array passed in.
   *
   * @return array
   *   Array of clean value.
   */
  public function cleanArrayValue(array $array) {

    $ret = [];

    // Remove spaces, tabs, new line returns.
    foreach ($array as $k => $value) {
      $ret[$k] = trim($value, " \t\n\r");
    }

    return $ret;
  }

  /**
   * Function takes in array of state abbreviations.
   *
   * @param array $states
   *   Array of state abbreviations from API.
   *
   * @return array
   *   Return an array of key => value else a empty array.
   *   Used for mapping state abbreviations to state name.
   */
  public function getHomeStateName(array $states) {

    $states_array = [
      'AL' => 'Alabama',
      'AK' => 'Alaska',
      'AZ' => 'Arizona',
      'AR' => 'Arkansas',
      'CA' => 'California',
      'CO' => 'Colorado',
      'CT' => 'Connecticut',
      'DE' => 'Delaware',
      'DC' => 'Washington DC',
      'FL' => 'Florida',
      'GA' => 'Georgia',
      'HI' => 'Hawaii',
      'ID' => 'Idaho',
      'IL' => 'Illinois',
      'IN' => 'Indiana',
      'IA' => 'Iowa',
      'KS' => 'Kansas',
      'KY' => 'Kentucky',
      'LA' => 'Louisiana',
      'ME' => 'Maine',
      'MD' => 'Maryland',
      'MA' => 'Massachusetts',
      'MI' => 'Michigan',
      'MN' => 'Minnesota',
      'MS' => 'Mississippi',
      'MO' => 'Missouri',
      'MT' => 'Montana',
      'NE' => 'Nebraska',
      'NV' => 'Nevada',
      'NH' => 'New Hampshire',
      'NJ' => 'New Jersey',
      'NM' => 'New Mexico',
      'NY' => 'New York',
      'NC' => 'North Carolina',
      'ND' => 'North Dakota',
      'OH' => 'Ohio',
      'OK' => 'Oklahoma',
      'OR' => 'Oregon',
      'PA' => 'Pennsylvania',
      'PR' => 'Puerto Rico',
      'RI' => 'Rhode Island',
      'SC' => 'South Carolina',
      'SD' => 'South Dakota',
      'TN' => 'Tennessee',
      'TX' => 'Texas',
      'UT' => 'Utah',
      'VT' => 'Vermont',
      'VI' => 'Virgin Islands',
      'VA' => 'Virginia',
      'WA' => 'Washington',
      'WV' => 'West Virginia',
      'WI' => 'Wisconsin',
      'WY' => 'Wyoming',
    ];

    $return_arr = [];

    if (is_array($states) && !empty($states)) {
      foreach ($states as $abbreviation) {
        $abbreviation = strtoupper(trim($abbreviation));
        // Check if key exist in array.
        if (!empty($abbreviation)) {
          if (array_key_exists($abbreviation, $states_array)) {
            $return_arr[$abbreviation] = $states_array[$abbreviation];
          }
          else {
            $return_arr[$abbreviation] = $abbreviation . ' Unknown';
          }
        }
      }
    }

    return $return_arr;
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($scholarship) {
    // Logger.
    $logger = $this->loggerFactory->get('du_scholarship_import');

    // EntityTypeManager.
    $node_storage = $this->entityTypeManager->getStorage('node');

    $isNew = FALSE;
    $need_update = FALSE;

    $scholarshipHash = $this->getHash($scholarship);

    // Find if the node already exists.
    $query = $node_storage->getQuery()
      ->condition('type', 'scholarship')
      ->condition('field_scholarship_code', $scholarship['code']);
    // Use Scholarship API Hash value for query.
    if (!empty($scholarshipHash)) {
      $query->condition('field_scholarship_api_hash', $scholarshipHash);
    }

    $nid = $query->accessCheck(TRUE)->execute();
    if (!empty($nid)) {
      // Load existing node.
      $node = $node_storage->load(reset($nid));
    }
    else {
      // Create new scholarship node.
      $node = $node_storage->create(['type' => 'scholarship']);
      $isNew = TRUE;
    }

    if (!$isNew) {
      // This importing scholarship is not new so check last modified date.
      $need_update = $this->checkScholarshipLastUpdate($scholarship, $node);
    }

    // Import/Update: if new node, last updated timestamp has changed or
    // need to published.
    if ($isNew || $need_update || !$node->isPublished()) {

      // Term storage.
      $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');

      // Set API update timestamp.
      $node->set('field_api_update_stamp', time());

      // Set Scholarship last update.
      $node->set('field_scholarship_last_update', strtotime($scholarship['lastUpdated']));

      // Set Scholarship API Hash.
      $node->set('field_scholarship_api_hash', $scholarshipHash);

      // Set Scholarship Name/Title.
      $node->set('title', html_entity_decode($scholarship['name']));

      // Set Scholarship Code.
      $node->set('field_scholarship_code', $scholarship['code']);

      // Set Scholarship Description.
      if (!empty($scholarship['description'])) {
        $node->set('field_scholarship_description', ['value' => $scholarship['description'], 'format' => 'rich_text']);
      }

      // Set Scholarship Class Level.
      if (!empty($scholarship['levels'][0])) {

        $class_levels = [];
        foreach ($scholarship['levels'] as $level) {
          switch ($level) {
            case "UG":
            case "UGGR":
              if (!in_array('first_current', $class_levels)) {
                $class_levels[] = ['value' => 'first_current'];
              }
              if (!in_array('second', $class_levels)) {
                $class_levels[] = ['value' => 'second'];
              }
              if (!in_array('third', $class_levels)) {
                $class_levels[] = ['value' => 'third'];
              }
              if (!in_array('fourth', $class_levels)) {
                $class_levels[] = ['value' => 'fourth'];
              }
              break;

            case "GR":
              break;

            case "High School/Incoming Freshman":
            case "Incoming First-Year Students":
              if (!in_array('first_incoming', $class_levels)) {
                $class_levels[] = ['value' => 'first_incoming'];
              }
              break;

            case "1":
              if (!in_array('first_current', $class_levels)) {
                $class_levels[] = ['value' => 'first_current'];
              }
              break;

            case "2":
              if (!in_array('second', $class_levels)) {
                $class_levels[] = ['value' => 'second'];
              }
              break;

            case "3":
              if (!in_array('third', $class_levels)) {
                $class_levels[] = ['value' => 'third'];
              }
              break;

            case "4":
              if (!in_array('fourth', $class_levels)) {
                $class_levels[] = ['value' => 'fourth'];
              }
              break;

            default:
          }
        }

        $node->set('field_scholarship_class_level', $class_levels);
      }

      // Set Scholarship Kind.
      if (!empty($scholarship['merit'][0]['meritType'])) {
        $node->set('field_scholarship_kind', ['value' => strtolower($scholarship['merit'][0]['meritType'])]);
      }

      // Set Scholarship Min GPA.
      if (!empty($scholarship['merit'][0]['minimumGPA'])) {
        $node->set('field_scholarship_minimum_gpa', ['value' => $scholarship['merit'][0]['minimumGPA']]);
      }

      // Set Scholarship Minimum Age.
      if (!empty($scholarship['minimumAge'])) {
        $node->set('field_scholarship_minimum_age', ['value' => $scholarship['minimumAge']]);
      }

      // Set Race Codes.
      if (!empty($scholarship['raceCodes'][0])) {
        $race_codes = [];
        foreach ($scholarship['raceCodes'] as $codes) {
          $race_codes[] = ['value' => $codes['id']];
        }

        $node->set('field_scholarship_race_codes', $race_codes);
      }

      // International.
      if ($scholarship['international']) {
        $node->set('field_scholarship_international', [['value' => 'yes']]);
      }

      // Set Scholarship Population Section.
      $population = [];

      // Student of color.
      if ($scholarship['studentsOfColor']) {
        $population[] = ['value' => 'students_color'];
      }

      // Gender Female.
      if (!empty($scholarship['gender']) && $scholarship['gender'] == 'F') {
        $population[] = ['value' => 'women'];
      }

      // Veterans.
      if ($scholarship['veterans']) {
        $population[] = ['value' => 'veterans'];
      }

      // Set the values for scholarship population.
      $node->set('field_scholarship_population', $population);

      // Set Home State.
      if (!empty($scholarship['states'])) {

        $homestates_tids = [];

        // Get states array having state abbreviation as key and
        // state name as value.
        $states = $this->getHomeStateName($scholarship['states']);

        if (!empty($states)) {
          // Import home states term.
          foreach ($states as $state) {
            if (!empty($state)) {
              // Get Location term in vocab.
              $term_properties = [
                'name' => $state,
                'vid' => 'location',
              ];
              $terms = $term_storage->loadByProperties($term_properties);
              if (!empty($terms)) {
                $term = reset($terms);
              }
              else {
                // Create new Location term to vocab.
                $term = $term_storage->create($term_properties);
                $term->save();
              }

              // Add term to array.
              $homestates_tids[] = ['target_id' => $term->id()];
            }
          }
        }

        // Set home state target ids.
        $node->set('field_scholarship_home_state', $homestates_tids);
      }

      $major_college_code_name_arr = [];
      $target_schools = [];
      $schools_tids = [];
      // Get scholarship schools/colleges.
      if (!empty($scholarship['colleges'])) {
        foreach ($scholarship['colleges'] as $school) {

          $code = $school['collegeCode'];
          if ($code == 'AH' || $code == 'SS') {
            $code = 'AHSS';
          }
          elseif ($code == 'TX') {
            $code = 'LW';
          }

          // Get School term in vocab.
          $term_properties = [
            'field_schools_banner_code' => $code,
            'vid' => 'schools',
          ];

          $terms = $term_storage->loadByProperties($term_properties);
          if (!empty($terms)) {
            $term = reset($terms);

            // Add term to array.
            $tid = $term->id();
            $target_schools[] = ['target_id' => $tid];
            $schools_tids[] = $tid;
            $major_college_code_name_arr[$code] = $tid;
          }
          else {
            // Create new School term to vocab.
            $term = $term_storage->create($term_properties);
            $term->save();

            // Add term to array.
            $tid = $term->id();
            $target_schools[] = ['target_id' => $tid];
            $schools_tids[] = $tid;
            $major_college_code_name_arr[$code] = $tid;
          }
        }

        // Set School target.
        $node->set('field_scholarship_school', $target_schools);
      }

      // Set Scholarship Major.
      if (!empty($scholarship['majors'])) {

        $target_majors = [];

        foreach ($scholarship['majors'] as $major) {

          // Get Scholarship Major term in vocab.
          $term_properties = [
            'field_major_code' => $major['majorCode'],
            'name' => $major['major'],
            'vid' => 'scholarship_major',
          ];

          $terms = $term_storage->loadByProperties($term_properties);
          if (!empty($terms)) {
            $term = reset($terms);

            // Add term to array.
            $target_majors[] = ['target_id' => $term->id()];
          }
          else {
            // Create new Scholarship Major term to vocab.
            $term = $term_storage->create($term_properties);
            $term->save();

            // Add term to array.
            $target_majors[] = ['target_id' => $term->id()];
          }

          // Process Major and School/College association.
          if (count($schools_tids) > 1) {
            // If more than one college provided,
            // Then use major's collegeCode to make association.
            $major_college_code = $major['collegeCode'];
            if ($major_college_code == 'AH' || $major_college_code == 'SS') {
              $major_college_code = 'AHSS';
            }
            elseif ($major_college_code == 'TX') {
              $major_college_code = 'LW';
            }

            // Get college target_id if available.
            $major_college_tid = [];
            if (isset($major_college_code_name_arr) and !empty($major_college_code_name_arr)) {
              if (isset($major_college_code_name_arr[$major_college_code]) and !empty($major_college_code_name_arr[$major_college_code])) {
                $college_target_id = $major_college_code_name_arr[$major_college_code];
                $major_college_tid[] = $college_target_id;
                $this->majorSchoolReferenceList($term, $major_college_tid);
              }
            }
          }
          elseif (count($schools_tids) == 1) {
            // If only one school provided,
            // then associate this major to this school.
            if (isset($schools_tids) && !empty($schools_tids)) {
              $this->majorSchoolReferenceList($term, $schools_tids);
            }
          }
        }

        // Set the major targets.
        $node->set('field_scholarship_major', $target_majors);
      }

      // Set imported scholarship to publish and save.
      $node->setPublished(TRUE);
      $node->set('moderation_state', 'published');
      if ($isNew) {
        $node->enforceIsNew();
      }
      $node->save();

      $logger->info(
        'The scholarship import queue worker imported scholarship ID: %scholarship_id.',
        ['%scholarship_id' => $node->id()]
      );
    }
    else {
      $logger->info(
        'The scholarship import queue worker skipped over scholarship ID %scholarship_id because it is already imported and nothing changed.',
        ['%scholarship_id' => $node->id()]
      );
    }
  }

  /**
   * Set references for School Major unit associations.
   *
   * @param object $term
   *   Node being updated.
   * @param array $schools_tids
   *   Array of school ids from the API.
   */
  protected function majorSchoolReferenceList($term, array $schools_tids) {
    $school_refs = [];
    $new_refs = [];

    // Get school references for the major term.
    $term_school_associations = array_column($term->field_scholarship_major_school->getValue(), 'target_id');

    if (!empty($term_school_associations)) {
      // Create current school association array.
      foreach ($term_school_associations as $id) {
        $school_refs[] = $id;
      }

      // Loop schools tids and add to new refs array if not in current list.
      foreach ($schools_tids as $tid) {
        if (!in_array($tid, $school_refs)) {
          $school_refs[] = $tid;
          $new_refs[] = $tid;
        }
      }
    }
    else {
      $new_refs = $schools_tids;
    }

    if (!empty($new_refs)) {
      // Loop new schools tids and add if not in current list.
      foreach ($new_refs as $tid) {
        // Append new school reference.
        $term->field_scholarship_major_school->appendItem(['target_id' => $tid]);
      }

      // Save the appended school references.
      $term->save();
    }

  }

  /**
   * Function to compare api lastUpdated date with Drupal last updated time.
   *
   * @param array $scholarship
   *   The scholarship array from API to be imported.
   * @param object $node
   *   Drupal Scholarship Node being updated.
   */
  protected function checkScholarshipLastUpdate(array $scholarship, $node) {
    // Logger.
    $logger = $this->loggerFactory->get('du_scholarship_import');

    // Get scholarship Code.
    $scholarshipCode = $scholarship['code'];
    $scholarshipTitle = html_entity_decode($scholarship['name']);

    $node_last_updated = $node->get('field_scholarship_last_update')->value;
    $scholarship_last_updated = NULL;
    $scholarship_updated = FALSE;

    if (!empty($scholarship['lastUpdated'])) {
      $scholarship_last_updated = strtotime($scholarship['lastUpdated']);
    }

    if ($node_last_updated <= $scholarship_last_updated) {

      $scholarship_updated = TRUE;
      $this->lockService->release($node->id(), $node->language);
      $node->save();
      $logger->info(
        'The scholarship (nid: %nid) for %scholarshipTitle (scholarshipCode: %scholarshipCode) has been updated.',
        [
          '%nid' => $node->id(),
          '%name' => $scholarshipTitle,
          '%scholarshipCode' => $scholarshipCode,
        ]
      );
    }
    else {

      $logger->info(
        'The scholarship (nid: %nid) for %scholarshipTitle (scholarshipCode: %scholarshipCode) did not require updating.',
        [
          '%nid' => $node->id(),
          '%name' => $scholarshipTitle,
          '%scholarshipCode' => $scholarshipCode,
        ]
      );
    }

    return $scholarship_updated;
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
