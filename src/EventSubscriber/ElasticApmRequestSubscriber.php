<?php

namespace Drupal\elastic_apm\EventSubscriber;

use function debug_backtrace;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;

use PhilKra\Agent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\PostResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The ElasticApm request event subscriber class.
 *
 * @package Drupal\elastic_apm\EventSubscriber
 */
class ElasticApmRequestSubscriber implements EventSubscriberInterface {

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
   * The PHP agent for the Elastic APM.
   *
   * @var \PhilKra\Agent
   */
  protected $phpAgent;

  /**
   * The current path.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The current database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

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
   * @param \Drupal\Core\Database\Connection $database
   *   The active database connection.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    AccountProxyInterface $account,
    RouteMatchInterface $route_match,
    Connection $database
  ) {
    $this->config = $config_factory->get('elastic_apm.configuration');
    $this->account = $account;
    $this->routeMatch = $route_match;
    $this->database = $database;

    // Initialize our PHP Agent.
    $this->phpAgent = new Agent(
      $this->config->get(),
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
      KernelEvents::TERMINATE => ['onTerminate', 300],
    ];

    return $events;
  }

  /**
   * Start a transaction for the PHP Agent whenever this event is triggered.
   *
   * @param \Symfony\Component\HttpKernel\Event\GetResponseEvent $event
   *   The request event object.
   *
   * @throws \PhilKra\Exception\Transaction\DuplicateTransactionNameException
   */
  public function onRequest(GetResponseEvent $event) {
    // If this is a sub request, only process it if there was no master
    // request yet. In that case, it is probably a page not found or access
    // denied page.
    if ($event->getRequestType() !== HttpKernelInterface::MASTER_REQUEST && $this->processedMasterRequest) {
      return;
    }

    // Start a new transaction.
    $transaction = $this->phpAgent->startTransaction($this->routeMatch->getRouteName());

    // Create a span to capture the database time.
    $spans = [];

    // TODO: Figure out what kind of db info goes here.
    $spans[] = [
      'name' => 'Database Query',
      'type' => 'db.mysql.query',
      'stacktrace' => debug_backtrace(),
    ];

    // Add the span to the transaction.
    $transaction->setSpans($spans);

    // Send our transaction to Elastic.
    $this->phpAgent->send();

    // Set processedMasterRequest to TRUE.
    $this->processedMasterRequest = TRUE;
  }

  /**
   * End the transaction and send to PHP Agent whenever this event is triggered.
   *
   * @param \Symfony\Component\HttpKernel\Event\PostResponseEvent $event
   *   The terminated event object.
   *
   * @throws \PhilKra\Exception\Transaction\UnknownTransactionException
   */
  public function onTerminate(PostResponseEvent $event) {
    // End the transaction.
    $this->phpAgent->stopTransaction($this->routeMatch->getRouteName());

    // Send our transaction to Elastic.
    $this->phpAgent->send();
  }

}
