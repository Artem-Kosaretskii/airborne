<?php

require_once __DIR__.'/src/init.php';

use AmoCRM\OAuth2\Client\Provider\AmoCRM;
use AmoCRM\OAuth2\Client\Provider\AmoCRMResourceOwner;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Grant\RefreshToken;
use League\OAuth2\Client\Grant\AuthorizationCode;

ini_set('display_errors', 1);
ini_set('error_log',__DIR__ .'/logs/phpErrors.log');
ini_set('log_errors', 1);
ini_set('error_reporting', E_ALL);
set_time_limit(0);

session_start();
$provider = new AmoCRM(['clientId' => CLIENT_ID, 'clientSecret' => SECRET, 'redirectUri' => REDIRECT_URI]);
file_put_contents(__DIR__.'/data/test.json',json_encode(['get' => $_GET, 'post' => $_POST, 'session' => $_SESSION]));
if (isset($_GET['referer'])) $provider->setBaseDomain($_GET['referer']);
if (!isset($_GET['request'])) {
    if (!isset($_GET['code'])) {
        // Просто отображаем кнопку авторизации или получаем ссылку для авторизации
        try {
            $_SESSION['oauth2state'] = bin2hex(random_bytes(16));
        } catch (Exception $e) {
        }
//        if (true) {
            echo '<div>
                <script
                    class="amocrm_oauth"
                    charset="utf-8"
                    data-client-id="' . $provider->getClientId() . '"
                    data-title="Установить интеграцию"
                    data-compact="false"
                    data-class-name="className"
                    data-color="default"
                    data-state="' . $_SESSION['oauth2state'] . '"
                    data-error-callback="handleOauthError"
                    src="'. BUTTON_LINK .'"
                ></script>
                </div>';
            echo '<script>
            handleOauthError = function(event) {
                alert(\'ID клиента - \' + event.client_id + \' Ошибка - \' + event.error);
            }
            </script>';
            die;
//        } else {
//            $authorizationUrl = $provider->getAuthorizationUrl(['state' => $_SESSION['oauth2state']]);
//            header('Location: ' . $authorizationUrl);
//        }
    } elseif (empty($_GET['state']) || empty($_SESSION['oauth2state']) || ($_GET['state'] !== $_SESSION['oauth2state'])) {
        unset($_SESSION['oauth2state']);
        exit('Invalid state');
    }
    // Ловим обратный код
    try {
        /** @var AccessToken $access_token */
        $accessToken = $provider->getAccessToken(new AuthorizationCode(), ['code' => $_GET['code']]);
        if (!$accessToken->hasExpired()) {
            $newAccessToken = $accessToken->getToken();
            $refreshToken = $accessToken->getRefreshToken();
            saveToken([
                'accessToken' => $newAccessToken,
                'refreshToken' => $refreshToken,
                'expires' => $accessToken->getExpires(),
                'baseDomain' => $provider->getBaseDomain(),
            ]);
            echo 'Access: '. $newAccessToken . PHP_EOL . PHP_EOL . 'Refresh: '. $refreshToken . PHP_EOL;
        }
    } catch (Exception $e) {
        die((string)$e);
    }
//    /** @var AmoCRMResourceOwner $ownerDetails */
//    $ownerDetails = $provider->getResourceOwner($accessToken);
//
//    printf('Hello, %s!', $ownerDetails->getName());
} else {
    $accessToken = getToken();
    $provider->setBaseDomain($accessToken->getValues()['baseDomain']);
    // Проверяем активен ли токен и делаем запрос или обновляем токен
    if ($accessToken->hasExpired()) {
        try {
            $accessToken = $provider->getAccessToken(new RefreshToken(), ['refresh_token' => $accessToken->getRefreshToken()]);
            $newAccessToken = $accessToken->getToken();
            $refreshToken = $accessToken->getRefreshToken();
            saveToken([
                'accessToken' => $newAccessToken,
                'refreshToken' => $refreshToken,
                'expires' => $accessToken->getExpires(),
                'baseDomain' => $provider->getBaseDomain(),
            ]);
            echo 'Access: '. $newAccessToken . PHP_EOL . PHP_EOL . 'Refresh: '. $refreshToken . PHP_EOL;
        } catch (Exception $e) {
            die((string)$e);
        }
    }
    $token = $accessToken->getToken();
//    try {
//        /**
//         * Делаем запрос к АПИ
//         */
//        $data = $provider->getHttpClient()
//            ->request('GET', $provider->urlAccount() . 'api/v2/account', [
//                'headers' => $provider->getHeaders($accessToken)
//            ]);
//
//        $parsedBody = json_decode($data->getBody()->getContents(), true);
//        printf('ID аккаунта - %s, название - %s', $parsedBody['id'], $parsedBody['name']);
//    } catch (GuzzleHttp\Exception\GuzzleException $e) {
//        var_dump((string)$e);
//    }
}

/**
 * @param $accessToken
 * @return void
 */
function saveToken($accessToken): void
{
    if (
        isset($accessToken)
        && isset($accessToken['accessToken'])
        && isset($accessToken['refreshToken'])
        && isset($accessToken['expires'])
        && isset($accessToken['baseDomain'])
    ) {
        $data = [
            'accessToken' => $accessToken['accessToken'],
            'expires' => $accessToken['expires'],
            'refreshToken' => $accessToken['refreshToken'],
            'baseDomain' => $accessToken['baseDomain'],
        ];

        file_put_contents(__DIR__.'/data/'.TOKEN_FILE, json_encode($data));
    } else {
        exit('Invalid access token ' . var_export($accessToken, true));
    }
}

/**
 * @return AccessToken
 */
function getToken(): AccessToken
{
    $accessToken = json_decode(file_get_contents(__DIR__.'/data/'.TOKEN_FILE), true);
    if (
        isset($accessToken)
        && isset($accessToken['accessToken'])
        && isset($accessToken['refreshToken'])
        && isset($accessToken['expires'])
        && isset($accessToken['baseDomain'])
    ) {
        return new AccessToken([
            'access_token' => $accessToken['accessToken'],
            'refresh_token' => $accessToken['refreshToken'],
            'expires' => $accessToken['expires'],
            'baseDomain' => $accessToken['baseDomain'],
        ]);
    } else {
        exit('Invalid access token ' . var_export($accessToken, true));
    }
}
