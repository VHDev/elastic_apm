<?php

namespace Drupal\elastic_apm;

/**
 * Interface for the APIService object.
 *
 * @package Drupal\elastic_apm
 */
interface ApiServiceInterface {

  /**
   * Returns whether Elastic APM is configured to capture errors and exceptions.
   *
   * @return array
   *   TRUE if errors and exceptions should be captured, FALSE otherwise.
   */
  public function captureThrowable();

  /**
   * Returns an initialized Elastic APM PHP Agent.
   *
   * @param array $options
   *   An array of options to pass to the agent when initializing. Ie. tags.
   *
   * @return \PhilKra\Agent
   *   The Elastic APM PHP agent.
   */
  public function getPhpAgent(array $options);

  /**
   * Returns an initialized Elastic APM RUM Agent script.
   *
   * @return string
   *   The Elastic APM RUM agent script.
   */
  public function getRumAgentScript();

  /**
   * Returns TRUE if the user has enabled Elastic APM PHP Agent.
   *
   * @return bool
   *   TRUE if it is enabled, FALSE otherwise.
   */
  public function isPhpAgentEnabled();

  /**
   * Returns TRUE if the user has enabled Elastic APM Rum Agent.
   *
   * @return bool
   *   TRUE if it is enabled, FALSE otherwise.
   */
  public function isRumAgentEnabled();

  /**
   * Returns TRUE if the Elastic APM php agent settings are configured.
   *
   * @return bool
   *   TRUE if the php agent settings are configured, FALSE otherwise.
   */
  public function isPhpAgentConfigured();

}
