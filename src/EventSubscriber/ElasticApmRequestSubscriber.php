<?php

namespace Drupal\elastic_apm\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Database;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Utility\Error;

use Exception;
use PhilKra\Agent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The ElasticApm request event subscriber class.
 *
 * @package Drupal\elastic_apm\EventSubscriber
 */
class ElasticApmRequestSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * The elastic_apm configuration.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The current account.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $account;

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
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The PHP agent for the Elastic APM.
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
   * Constructs a ElasticApmRequestSubscriber object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Session\AccountProxyInterface $account
   *   The current account.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    AccountProxyInterface $account,
    RouteMatchInterface $route_match,
    LoggerInterface $logger,
    MessengerInterface $messenger
  ) {
    $this->config = $config_factory->get('elastic_apm.configuration');
    $this->account = $account;
    $this->routeMatch = $route_match;
    $this->logger = $logger;
    $this->messenger = $messenger;

    // Initialize our PHP Agent.
    // Fetch the configs.
    $elastic_config = $this->config->get();
    // Set the apmVersion to v1 if it's empty as the PHP Agent doesn't.
    if (empty($elastic_config['apmVersion'])) {
      $elastic_config['apmVersion'] = 'v1';
    }

    $this->phpAgent = new Agent(
      $elastic_config,
      [
        'user' => [
          'id' => $this->account->id(),
          'email' => $this->account->getEmail(),
        ],
      ]
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      KernelEvents::REQUEST => ['onRequest', 30],
      KernelEvents::RESPONSE => ['onResponse', 300],
    ];

    return $events;
  }

  /**
   * Start a transaction for the PHP Agent whenever this event is triggered.
   *
   * @param \Symfony\Component\HttpKernel\Event\GetResponseEvent $event
   *   The request event object.
   */
  public function onRequest(GetResponseEvent $event) {
    // If this is a sub request, only process it if there was no master
    // request yet. In that case, it is probably a page not found or access
    // denied page.
    if ($event->getRequestType() !== HttpKernelInterface::MASTER_REQUEST && $this->processedMasterRequest) {
      return;
    }

    // Start a new transaction.
    try {
      $transaction = $this->phpAgent->startTransaction($this->routeMatch->getRouteName());

      // Capture database time by wrapping spans around the db queries run.
      $transaction->setSpans($this->constructDatabaseSpans());
    }
    catch (Exception $e) {
      // Notify the user of the error.
      $this->messenger->addError(t('An error occurred while trying to send the transaction to the Elastic APM server.'));

      // Log the error to watchdog.
      $error = Error::decodeException($e);
      $this->logger->log($error['severity_level'], '%type: @message in %function (line %line of %file).', $error);
    }

    // Mark the request as processed.
    $this->processedMasterRequest = TRUE;
  }

  /**
   * End the transaction and send to PHP Agent whenever this event is triggered.
   *
   * @param \Symfony\Component\HttpKernel\Event\FilterResponseEvent $event
   *   The event to process.
   */
  public function onResponse(FilterResponseEvent $event) {
    // End the transaction.
    try {
      $this->phpAgent->stopTransaction($this->routeMatch->getRouteName());

      // Send our transaction to Elastic.
      $this->phpAgent->send();
    }
    catch (Exception $e) {
      // Notify the user of the error.
      $this->messenger->addError(t('An error occurred while trying to send the transaction to the Elastic APM server.'));

      // Log the error to watchdog.
      $error = Error::decodeException($e);
      $this->logger->log($error['severity_level'], '%type: @message in %function (line %line of %file).', $error);
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

    // First, go through all queries that have been run for this request.
    $connections = [];
    foreach (Database::getAllConnectionInfo() as $key => $info) {
      $database = Database::getConnection('default', $key);
      $connections[$key] = $database->getLogger()->get('elastic_apm');
    }

    // Now, create a span for each query that was run.
    foreach ($connections as $key => $queries) {
      foreach ($queries as $query) {
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
            'instance' => $key,
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

        $spans[] = $span;
      }
    }

    return $spans;
  }

}
