<?php

namespace Drupal\elastic_apm\EventSubscriber;

use Drupal\elastic_apm\ApiServiceInterface;

use Drupal\Core\Database\Database;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\PostResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The ElasticApm request event subscriber class.
 *
 * @package Drupal\elastic_apm\EventSubscriber
 */
class RequestSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * The Elastic APM service object.
   *
   * @var \Drupal\elastic_apm\ApiServiceInterface
   */
  protected $apiService;

  /**
   * The current path.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The actual PHP Agent for the Elastic APM server.
   *
   * @var \PhilKra\Agent
   */
  protected $phpAgent;

  /**
   * A flag whether the master request was processed.
   *
   * @var bool
   */
  protected $processedMasterRequest = FALSE;

  /**
   * Constructs a RequestSubscriber object.
   *
   * @param \Drupal\elastic_apm\ApiServiceInterface $api_service
   *   The Elastic APM service object.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(
    ApiServiceInterface $api_service,
    RouteMatchInterface $route_match,
    LoggerInterface $logger
  ) {
    $this->apiService = $api_service;
    $this->routeMatch = $route_match;
    $this->logger = $logger;

    // Initialize the PHP agent if the Elastic APM config is configured.
    if ($this->apiService->isEnabled() && $this->apiService->isConfigured()) {
      // Let's pass some options to the Agent depending on the request.
      $this->phpAgent = $this->apiService->getAgent($this->prepareAgentOptions());
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      KernelEvents::REQUEST => ['onRequest', 30],
      KernelEvents::TERMINATE => ['onKernelTerminate', 300],
    ];
  }

  /**
   * Start a transaction for the PHP Agent whenever this event is triggered.
   *
   * @param \Symfony\Component\HttpKernel\Event\GetResponseEvent $event
   *   The request event object.
   */
  public function onRequest(GetResponseEvent $event) {
    // Return if Elastic isn't enabled.
    if (!$this->apiService->isEnabled()) {
      return;
    }

    // Don't process if Elastic APM is not configured.
    if (!$this->apiService->isConfigured()) {
      return;
    }

    // If this is a sub request, only process it if there was no master
    // request yet. In that case, it is probably a page not found or access
    // denied page.
    $is_master = $event->getRequestType() !== HttpKernelInterface::MASTER_REQUEST;
    if ($is_master && $this->processedMasterRequest) {
      return;
    }

    // Start a new transaction.
    try {
      $transaction = $this->phpAgent
        ->startTransaction($this->routeMatch->getRouteName());

      // Capture database time by wrapping spans around the db queries run.
      $transaction->setSpans($this->constructDatabaseSpans());
    }
    catch (Exception $e) {
      // Log the error.
      $this->logger->error(
        sprintf(
          'An error occurred while trying to start a transaction for the Elastic
           APM server. The error was: "%s".',
          $e->getMessage()
        )
      );
    }

    // Mark the request as processed.
    $this->processedMasterRequest = TRUE;
  }

  /**
   * End the transaction and send to PHP Agent whenever this event is triggered.
   *
   * @param \Symfony\Component\HttpKernel\Event\PostResponseEvent $event
   *   The event to process.
   */
  public function onKernelTerminate(PostResponseEvent $event) {
    // Return if Elastic isn't enabled.
    if (!$this->apiService->isEnabled()) {
      return;
    }

    // Don't process if Elastic APM is not configured.
    if (!$this->apiService->isConfigured()) {
      return;
    }

    // Don't process if we don't have a PHP Agent already initialized, meaning,
    // no transaction is in process.
    if (!$this->phpAgent) {
      return;
    }

    // End the transaction.
    try {
      $this->phpAgent->stopTransaction($this->routeMatch->getRouteName());

      // Send our transaction to Elastic.
      $this->phpAgent->send();
    }
    catch (Exception $e) {
      // Log the error.
      $this->logger->error(
        sprintf(
          'An error occurred while stopping and sending the transaction to the
           Elastic APM server. The error was: "%s".',
          $e->getMessage()
        )
      );
    }
  }

  /**
   * Create spans around the db queries that were run in this request.
   *
   * @return array
   *   An array of spans that will be added to the transaction.
   */
  protected function constructDatabaseSpans() {
    $spans = [];

    foreach (array_keys(Database::getAllConnectionInfo()) as $key) {
      $database_log = Database::startLog('elastic_apm', $key);
      foreach ($database_log->get('elastic_apm') as $query) {
        $spans[] = $this->constructQuerySpan($key, $query);
      }
    }

    return $spans;
  }

  /**
   * Create a span for an individual database query.
   *
   * @param string $connection
   *   The database connection name.
   * @param array $query
   *   An array of information about the query that was run.
   *
   * @return array
   *   An array of necessary information about the query to send to Elastic.
   */
  protected function constructQuerySpan($connection, array $query) {
    $span = [];

    // Add the necessary schema info for the APM server.
    $span['name'] = $query['caller']['function'];
    $span['type'] = 'db.mysql.query';
    // This is the query start time relative to the transaction start.
    $span['start'] = 0;
    // Change duration time of query to milliseconds.
    $span['duration'] = $query['time'] * 1000;
    $span['context'] = [
      'db' => [
        'instance' => $connection,
        'statement' => $query['query'],
        'type' => 'sql',
      ],
    ];
    $span['stacktrace'] = [
      [
        'function' => $query['caller']['function'],
        'abs_path' => $query['caller']['file'],
        'filename' => substr($query['caller']['file'], strrpos($query['caller']['file'], '/') + 1),
        'lineno' => $query['caller']['line'],
        'vars' => $query['args'],
      ],
    ];

    return $span;
  }

  /**
   * Add options to pass to the PHP Agents.
   *
   * Currently we are just tagging admin pages.
   *
   * @return array
   *   An array of options to pass to the PHP Agent. Ie. tags.
   */
  protected function prepareAgentOptions() {
    $route_options = $this->routeMatch->getRouteObject()->getOptions();
    if (isset($route_options['_admin_route'])) {
      return ['tags' => ['is_admin_route' => TRUE]];
    }

    return [];
  }

}
