<?php

/**
 * @file
 * class Drupal|box_api_sitewide|BoxApiSitewide|OAuth2Client
 *
 * Extension of the Oauth2_client module's client class.
 */

namespace Drupal\box_api_sitewide\BoxApiSitewide\OAuth2;

/**
 * Get the class OAuth2\Client.
 */
  if (function_exists('module_load_include')) {
    module_load_include('inc', 'oauth2_client');
  }
  else {
    include_once('../../../contrib/oauth2_client/oauth2_client.inc');
  }

use Drupal\service_container\Variable;

/**
 * Class Client
 * @package Drupal\box_api_sitewide|BoxApiSitewide|OAuth2|Client
 *
 * Identical to the oauth2_client module, except:
 * - it satisfies Box's requirement for including client_id and client_secret in the token request.
 * - includes a setter for token information, so we can resume sessions as long as the refresh key is alive.
 * - stores the tokens in a serialized variable instead of $_SESSION.
 *
 * @codeCoverageIgnore
 */
class Client extends \OAuth2\Client {

  /**
   * Construct an OAuth2\Client object.
   * Runs the parent, then gets saved tokens from the variables table instead of
   * $_SESSION.
   *
   * @param \Drupal\service_container\Variable $variable
   *   The variable service.
   * @param array $params
   *   Optional configuration parameters
   * @param string $id
   *   Optional ID
   */
  public function __construct(Variable $variable, $params = NULL, $id = 'box_api_sitewide') {
    parent::__construct($params, $id);
    $this->variable = $variable;

    // Get the token data from the variables table, if it is stored there.
    $saved_tokens = $this->variable->get('box_api_sitewide_oauth2_tokens', array());
    if (isset($saved_tokens[$this->id])) {
      $this->token = $saved_tokens[$this->id] + $this->token;
    }
  }

  /**
   * Set the connection parameters
   *
   * @param array $params
   * Associative array of the parameters that are needed
   * by the different types of authorization flows.
   *  - auth_flow :: server-side | client-credentials | user-password
   *  - client_id :: Client ID, as registered on the oauth2 server
   *  - client_secret :: Client secret, as registered on the oauth2 server
   *  - token_endpoint :: something like:
   *       https://oauth2_server.example.org/oauth2/token
   *  - authorization_endpoint :: somethig like:
   *       https://oauth2_server.example.org/oauth2/authorize
   *  - redirect_uri :: something like:
   *       url('oauth2/authorized', array('absolute' => TRUE)) or
   *       https://oauth2_client.example.org/oauth2/authorized
   *  - scope :: requested scopes, separated by a space
   *  - username :: username of the resource owner
   *  - password :: password of the resource owner
   */
  public function setParams($params) {
    $this->params = $params + $this->params;
  }

  /**
   * Token Getter.
   * Identical to the parent, except box ID and secret are included in the token request.
   */
  protected function getToken($data) {
    if (isset($data['scope']) and $data['scope'] == NULL) {
      unset($data['scope']);
    }

    $client_id = $this->params['client_id'];
    $client_secret = $this->params['client_secret'];
    $token_endpoint = $this->params['token_endpoint'];

    // Client ID and secret are a matching pair. If we have to reset one, we reset both.
    if (!isset($data['client_id']) || !isset($data['client_secret'])) {
      $data['client_id'] = $client_id;
      $data['client_secret'] = $client_secret;
    }

    $options = array(
      'method' => 'POST',
      'data' => drupal_http_build_query($data),
      'headers' => array(
        'Content-Type' => 'application/x-www-form-urlencoded',
      ),
    );
    $result = drupal_http_request($token_endpoint, $options);

    if ($result->code != 200) {
      throw new \Exception(
        t("Failed to get an access token of grant_type @grant_type.\nError: @result_error",
          array(
            '@grant_type' => $data['grant_type'],
            '@result_error' => $result->error,
          ))
      );
    }

    return (Array) json_decode($result->data);
  }

  /**
   * Setter for the token. Because sometimes we need to do that.
   * @param $token
   */
  public function setToken($token) {
    if ($token) {
      $this->token = $token;
    }
  }

  /**
   * Get and return an access token.
   *
   * Identical to the parent method, except we store tokens in a variable rather
   * than $_SESSION.
   */
  public function getAccessToken() {
    // Check wheather the existing token has expired.
    // We take the expiration time to be shorter by 10 sec
    // in order to account for any delays during the request.
    // Usually a token is valid for 1 hour, so making
    // the expiration time shorter by 10 sec is insignificant.
    // However it should be kept in mind during the tests,
    // where the expiration time is much shorter.
    $expiration_time = $this->token['expiration_time'];
    if ($expiration_time > (time() + 10)) {
      // The existing token can still be used.
      return $this->token['access_token'];
    }

    try {
      // Try to use refresh_token.
      $token = $this->getTokenRefreshToken();
    }
    catch (\Exception $e) {
      // Get a token.
      switch ($this->params['auth_flow']) {
        case 'client-credentials':
          $token = $this->getToken(array(
            'grant_type' => 'client_credentials',
            'scope' => $this->params['scope'],
          ));
          break;

        case 'user-password':
          $token = $this->getToken(array(
            'grant_type' => 'password',
            'username' => $this->params['username'],
            'password' => $this->params['password'],
            'scope' => $this->params['scope'],
          ));
          break;

        case 'server-side':
          $token = $this->getTokenServerSide();
          break;

        default:
          throw new \Exception(t('Unknown authorization flow "!auth_flow". Suported values for auth_flow are: client-credentials, user-password, server-side.',
            array('!auth_flow' => $this->params['auth_flow'])));
          break;
      }
    }
    $token['expiration_time'] = REQUEST_TIME + $token['expires_in'];

    // Store the token (in variables table as well).
    $this->token = $token;
    $saved_tokens = $this->variable->get('box_api_sitewide_oauth2_tokens', array());
    $saved_tokens[$this->id] = $token;
    $this->variable->set('box_api_sitewide_oauth2_tokens', $saved_tokens);

    // Redirect to the original path (if this is a redirection
    // from the server-side flow).
    self::redirect();

    // Return the token.
    return $token['access_token'];
  }
}