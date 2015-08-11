<?php

/**
 * @file
 * Contains \Drupal\box_api_sitewide\BoxApiSitewide|BoxConnection.
 */

namespace Drupal\box_api_sitewide\BoxApiSitewide;

use Drupal\service_container\Variable;
use Drupal\box_api_sitewide\BoxApiSitewide\OAuth2\Client;

/**
 * Class BoxConnection
 * @package Drupal\box_api_sitewide\BoxApiSitewide
 *
 * Represents a connection to the Box API. Otherwise a dumb Oauth2 HTTP connection.
 */
class BoxConnection {

  /**
   * @Variable
   *   Variable storage
   */
  protected $variable;

  /**
   * @OAuth2\Client
   *   The OAuth2 client resource, maintains the tokens.
   */
  protected $oAuth2Client;

  /**
   * @var null|string
   *   API Key.
   */
  protected $clientId;

  /**
   * clientId Getter.
   * @return null|string
   */
  public function getClientId() {
    return $this->clientId;
  }

  /**
   * @var null|string
   *   API Secret.
   */
  protected $clientSecret;

  /**
   * clientSecret Getter.
   * @return null|string
   */
  public function getClientSecret() {
    return $this->clientSecret;
  }

  /**
   * @var null|string
   *   Authentication API endpoint.
   */
  protected $boxEndpoint;

  /**
   * Endpoint Getter.
   * @return null|string
   */
  public function getBoxEndpoint() {
    return $this->boxEndpoint;
  }

  /**
   * @var null|string
   *   Content API endpoint.
   */
  protected $boxContentEndpoint;

  /**
   * @return null|string
   */
  public function getBoxContentEndpoint() {
    return $this->boxContentEndpoint;
  }

  /**
   * @var string
   *   The redirect base URL used in oauth authorization.
   */
  protected $boxRedirectBase;

  /**
   * Redirect base Getter.
   * @return string
   */
  public function getBoxRedirectBase() {
    return $this->boxRedirectBase;
  }

  /**
   * @var string
   *   The prefix used for storing data related to this connection.
   */
  protected $prefix;

  /**
   * Assign variable storage.
   *
   * @param \Drupal\service_container\Variable $variable
   *   The variable storage.
   * @param \Drupal\box_api_sitewide\BoxApiSitewide\OAuth2\Client $client
   *   The OAuth2 client library to use.
   */
  public function __construct(Variable $variable, Client $client) {
    // Assign variable storage.
    $this->variable = $variable;

    // Load our settings.
    $this->clientId = $this->variable->get('box_api_sitewide_client_id', 'FALSE');
    $this->clientSecret = $this->variable->get('box_api_sitewide_client_secret', FALSE);
    $this->boxEndpoint = $this->variable->get('box_api_sitewide_endpoint', 'https://app.box.com');
    $this->boxContentEndpoint = $this->variable->get('box_api_sitewide_content_endpoint', 'https://api.box.com');

    // Build a default base URL. Must be HTTPS.
    global $base_url;
    $url = parse_url($base_url);
    $baseurl_default = 'https://' . $url['host'];

    $this->boxRedirectBase = $this->variable->get('box_api_sitewide_redirect_base', $baseurl_default);

    // Assign OAuth2 client.
    $this->oAuth2Client = $client;

    // Load our parameters into it.
    $oauth2_config = array(
      'token_endpoint' => $this->boxEndpoint . '/api/oauth2/token',
      'auth_flow' => 'server-side',
      'client_id' => $this->clientId,
      'client_secret' => $this->clientSecret,
      'authorization_endpoint' => $this->boxEndpoint . '/api/oauth2/authorize',
      'redirect_uri' => $this->boxRedirectBase . '/oauth2/authorized',
    );
    $this->oAuth2Client->setParams($oauth2_config);

  }
  
  /**
   * Ensure we have a valid connection resource and updated token.
   */
  public function authorize() {
    // If there's a token saved in variables
    $saved_tokens = $this->variable->get('box_api_sitewide_oauth2_tokens', array());
    if (!empty($saved_tokens) && $token = $saved_tokens['box_api_sitewide']) {
      $this->oAuth2Client->setToken($token);
      return TRUE;
    }
    else {
      // There's no saved token, so generate one.
      $this->oAuth2Client->getAccessToken();
      return TRUE;
    }
  }


  /**
   * Get authentication related header to include with every query. If we don't have a valid token yet, create one.
   *
   * @return array
   *   Array of headers required for authentication.
   */
  protected function getAuthHeader() {
    $token = $this->oAuth2Client->getAccessToken();
    return array(
      'Authorization' => 'Bearer ' . $token,
    );
  }

  /**
   * Actually perform an HTTP request against the remote resource.
   * @param string $resource
   *   Can be one of 'directory', 'file_info', or 'file_content'
   * @param array $id
   *   Box ID of the resource to get.
   *
   * @return array
   *   The json-decoded response body.
   */
  public function request($resource = 'file', $id) {
    // Get our authentication header.
    $headers = $this->getAuthHeader();
    // Set gzip, deflate allowed.
    //$headers['Accept-Encoding'] = 'gzip, deflate';

    // Build the variables for the request.
    $path = '';
    $method = 'GET';
    $data = '';
    switch ($resource) {
      case 'folder':
        $path = '/2.0/folders/' . $id;
        break;
      case 'file':
        $path = '/2.0/files/' . $id;
        break;
      case 'file_content':
        $path = '/2.0/files/' . $id . '/content';
        break;
      case 'file_share':
        $path = '/2.0/files/' . $id;
        $method = 'PUT';
        $data = '{"shared_link": {"access": "open"}}';
    }
    $response = drupal_http_request($this->boxContentEndpoint . $path, $options = array('headers' => $headers, 'method' => $method, 'data' => $data));

    if ($response->code == '200') {
      if ($resource != 'file_content') {
        return json_decode($response->data);
      }
      else {
        return $response->data;
      }
    }
    else {
      drupal_set_message(t('Request returned error %error : %message', array('%error' => $response->code, '%message' => $response->status_message)));
      return NULL;
    }
  }
} 