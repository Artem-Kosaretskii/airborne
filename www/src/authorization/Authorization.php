<?php
declare(strict_types=1);

namespace Airborne;

use Exception;
use AmoCRM\Client\AmoCRMApiClient;
use AmoCRM\OAuth2\Client\Provider\AmoCRM;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Grant\RefreshToken;
use League\OAuth2\Client\Token\AccessTokenInterface;

class Authorization
{

private Logger $logger;
public AmoCRMApiClient $api_client;
public AccessToken $access_token;

    public function __construct()
{
    $this->logger = new Logger(__DIR__ . '/../../logs/' . basename(__FILE__, '.php') . '.log');
    $this->api_client = new AmoCRMApiClient(CLIENT_ID, SECRET, REDIRECT_URI);
    $this->access_token = $this->getToken();
    // $this->logger->log('Access token: '.$this->access_token);
    $provider = new AmoCRM(['clientId' => CLIENT_ID, 'clientSecret' => SECRET, 'redirectUri' => REDIRECT_URI]);
    $provider->setBaseDomain($this->access_token->getValues()['baseDomain']);
    if ($this->access_token->hasExpired()) {
        try {
            $accessToken = $provider->getAccessToken(new RefreshToken(), ['refresh_token' => $this->access_token->getRefreshToken()]);
            $newAccessToken = $accessToken->getToken();
            $refreshToken = $accessToken->getRefreshToken();
            $this->logger->log('New access token: '.$newAccessToken);
            $this->logger->log('New refresh token: '.$refreshToken);
            $this->saveToken([
                'accessToken' => $newAccessToken,
                'refreshToken' => $refreshToken,
                'expires' => $accessToken->getExpires(),
                'baseDomain' => $provider->getBaseDomain(),
            ]);
        } catch (Exception $error) {
            die((string)$error);
        }
    }
    $this->api_client->setAccessToken($this->access_token)
        ->setAccountBaseDomain($this->access_token->getValues()['baseDomain'])
        ->onAccessTokenRefresh(
            function (AccessTokenInterface $access_token, string $base_domain) {
                $this->saveToken(['accessToken' => $access_token->getToken(), 'refreshToken' => $access_token->getRefreshToken(), 'expires' => $access_token->getExpires(), 'baseDomain' => $base_domain]);
            });
}

    /**
     * @param $accessToken
     * @return void
     */
    private function saveToken($accessToken): void
{
    if (
        isset($accessToken) &&
        isset($accessToken['accessToken']) &&
        isset($accessToken['refreshToken']) &&
        isset($accessToken['expires']) &&
        isset($accessToken['baseDomain']))
    {
        $data =
            [
                'accessToken' => $accessToken['accessToken'],
                'expires' => $accessToken['expires'],
                'refreshToken' => $accessToken['refreshToken'],
                'baseDomain' => $accessToken['baseDomain'],
            ];
        file_put_contents(__DIR__.'/../../data/'.TOKEN_FILE, json_encode($data));
    } else {
        exit('Invalid access token ' . var_export($accessToken, true));
    }
}

/**
 * @return AccessToken
 */
private function getToken(): AccessToken
{
    $accessTokenParams = json_decode(file_get_contents(__DIR__.'./../../data/'.TOKEN_FILE), true);
    if (
        isset($accessTokenParams) &&
        isset($accessTokenParams['accessToken']) &&
        isset($accessTokenParams['refreshToken']) &&
        isset($accessTokenParams['expires']) &&
        isset($accessTokenParams['baseDomain']))
    {
        return new AccessToken(
            [
                'access_token' => $accessTokenParams['accessToken'],
                'refresh_token' => $accessTokenParams['refreshToken'],
                'expires' => $accessTokenParams['expires'],
                'baseDomain' => $accessTokenParams['baseDomain']
            ]);
    } else {
        exit('Invalid access token ' . var_export($accessTokenParams, true));
    }
}

}