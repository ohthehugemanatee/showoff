<?php

/**
 * @file 
 */

/**
 * @file
 * Contains \Drupal\oauth2_client_sitewide\OAuth2ClientSitewideController
 */


use Drupal\oauth2_client_sitewide\OAuth2\Client;

/**
 * Class OAuth2ClientSitewideController
 * @package Drupal\oauth2_client_sitewide
 *
 * Controller for connections to remote OAuth2 resources.
 */
class OAuth2ClientSitewideController {
  protected $connection;
  
  public function __construct(Client $client) {
    $this->connection = $client;
  }

  public function getConnection(string $configuration_id) {
    $configuration = ctools_get_exportable_config($configuration_id); // made that function up.
    $this->connection->connect($configuration);
    return $this->connection;
  }

}
