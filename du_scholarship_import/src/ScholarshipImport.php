<?php

namespace Drupal\du_scholarship_import;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Component\Serialization\Json;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Scholarship Import service class.
 */
class ScholarshipImport {

  /**
   * An ACME Services - Contents HTTP Client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The config factory interface.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs an ScholarshipImport object.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   An HTTP client that can perform remote requests.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger
   *   The logger factory.
   */
  public function __construct(
      ClientInterface $http_client,
      ConfigFactoryInterface $config_factory,
      LoggerChannelFactoryInterface $logger) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->loggerFactory = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
        $container->get('logger.factory'),
        $container->get('http_client'),
        $container->get('config.factory')
    );
  }

  /**
   * Query the Scholarship API for scholarships.
   *
   * @return array
   *   Return array of scholarships.
   */
  public function getScholarships() {
    // Logger.
    $logger = $this->loggerFactory->get('du_scholarship_import');

    // Config.
    $config = $this->configFactory->get('du_scholarship_import.settings');

    // Config variables.
    $url = $config->get('api_url');
    $client_id = $config->get('client_id');
    $client_secret = $config->get('client_secret');

    // Check for API URL before proceeding.
    if (empty($url)) {
      // Log error.
      $logger->error(
        'The scholarship importer was executed but lacks an API URL. Go to %path to configure.',
        [
          '%path' => '/admin/config/content/du_scholarship_import',
        ]
      );
      return FALSE;
    }

    // Add credentials to url query if they are set.
    $query = '';
    if (!empty($client_id) && !empty($client_secret)) {
      $query = http_build_query([
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'public' => 'true',
      ]);
    }

    // Array storing API endpoint scholarships to be returned.
    $scholarships = [];

    try {
      $response = $this->httpClient->get(
          $url . '?' . $query,
          ['headers' => ['Accept' => 'application/json']]
      );
      if ($response->getStatusCode() != 200) {
        throw new \Exception(getStatusCode());
      }
      $data = Json::decode((string) $response->getBody());
      if (!empty($data)) {
        $scholarships = array_merge($scholarships, $data);
      }
    }
    catch (\Exception $e) {
      // Log error.
      $logger->error(
        'An attempt to import scholarships from @url failed with a @status status code.',
        [
          '@url' => $url . '?' . $query,
          '@status' => $e->getCode(),
        ]
      );
    }

    return $scholarships;
  }

}
