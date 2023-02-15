<?php
declare(strict_types=1);

namespace Airborne;

class HttpClient
{
    public Logger $logger;
    public Logger $badlog;
    public int $retry;

    public function __construct()
    {
        $this->logger = new Logger(__DIR__ . '/../../logs/' . basename(__FILE__, '.php') . '.log');
        $this->badlog = new Logger(__DIR__ . '/../../logs/' . basename(__FILE__, '.php') . 'Errors.log');
        $this->retry = 0;
    }
    /**
     * Отправка CURL запроса
     * @param string $method
     * @param string $link
     * @param array $headers
     * @param array|null $body
     * @param bool $amoagent
     * @param bool $amo_front
     * @return string
     */
    public function makeRequest(string $method, string $link, array $headers, array $body = null, bool $amoagent = false, bool $amo_front = false): string
    {
        $curl = curl_init();
        if (isset($body)) {
            $amo_front ? curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($body)) : curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
        }
        if ($amoagent) curl_setopt($curl, CURLOPT_USERAGENT, 'amoCRM-oAuth-client/1.0');
        curl_setopt($curl, CURLOPT_URL, $link);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);
        $response = curl_exec($curl);
        $response_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        // $this->logger->log('Code ' . $response_code . ' from request to ' . $link);
        curl_close($curl);
//        if ($response_code === 200 || $response_code === 202 || $response_code === 204) {
            return (string)$response;
//        } elseif (!$response_code || $response_code >= 400) {
//            if ($this->retry >= ERROR_ATTEMPTS) {
//                $this->logger->log('Не удалось отправить запрос после ' . $this->retry . ' попыток, выход');
//                die();
//            }
//            $this->logger->log('Ошибка ' . $response_code . ', повторная попытка отправки запроса номер ' . ++$this->retry);
//            sleep(SECONDS_FOR_SLEEP);
//            $response = $this->makeRequest($method, $link, $headers, $body, $amoagent);
//        } else {
//            $this->logger->log('Ошибка при отправке запроса');
//        }
//        return (string)$response;
    }
}