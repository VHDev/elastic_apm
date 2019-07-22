<?php


namespace Drupal\elastic_apm;

/**
 * Interface for the APIService object.
 *
 * @package Drupal\elastic_apm
 */
interface ApiServiceInterface {

  /**
   * Returns the Elastic APM tag configuration array.
   *
   * @return array
   *   An array of Elastic APM tag configs.
   */
  public function getTagConfig();

  /**
   * Returns the Elastic APM configuration array.
   *
   * @return array
   *   An array of Elastic APM configs.
   */
  public function getConfig();

  /**
   * Returns an initialized Elastic APM PHP Agent.
   *
   * @param array $options
   *   An array of options to pass to the agent when initializing. Ie. tags.
   * @return \PhilKra\Agent
   *   The Elastic APM PHP agent.
   */
  public function getAgent(array $options);

  /**
   * Returns TRUE if the user has enabled Elastic APM.
   *
   * @return bool
   *   TRUE if it is enabled.
   */
  public function isEnabled();

  /**
   * Returns TRUE if the elastic_apm config object is configured.
   *
   * @return bool
   *   TRUE if the config object is configured.
   */
  public function isConfigured();

}
