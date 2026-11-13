<?php

/**
 * @file
 * Contains \Drupal.
 */

use Drupal\Core\DependencyInjection\ContainerNotInitializedException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Static Service Container wrapper.
 *
 * Generally, code in Drupal should accept its dependencies via either
 * constructor injection or setter method injection.
 *
 * @ingroup container
 */
class Drupal {

  /**
   * The current system version.
   */
  const VERSION = '10.3.6';

  /**
   * Core API compatibility.
   */
  const CORE_COMPATIBILITY = '8.x';

  /**
   * Core minimum schema version.
   */
  const CORE_MINIMUM_SCHEMA_VERSION = 8000;

}
