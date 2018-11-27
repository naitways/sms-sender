<?php

declare(strict_types=1);

namespace SmsSender;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use Psr\Log\LoggerInterface;

class Sender
{
    /** @var \Psr\Log\LoggerInterface $logger */
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    private function formatForLog(string $message): string
    {
        return str_replace(['\r\n', '\n', '\r'], '<br />', $message);
    }

    public function send(string $host, string $userName, string $password, string $phoneNumber, string $message): bool
    {
        $auth = base64_encode($userName . ':' . md5($password));

        $cookie = new SetCookie();
        $cookie->setName('Authorization');
        $cookie->setValue('Basic ' . $auth);
        $cookie->setPath('/');
        $cookie->setDomain($host);

        $cookieJar = new CookieJar();
        $cookieJar->setCookie($cookie);

        $authClient = new Client([
            'base_uri' => 'http://' . $host,
            'cookies' => $cookieJar,
        ]);
        $authUri = 'http://' . $host . '/userRpm/LoginRpm.htm?Save=save';
        $authRequest = new Request('GET', $authUri);

        $authResponse = null;
        try {
            $authResponse = $authClient->send($authRequest);
        } catch (GuzzleException $e) {
            $this->logger->error('An error occur during the login request : request was not sent', [
                'uri' => $authUri,
                'exception' => $e,
            ]);
        }

        $statusCode = $authResponse->getStatusCode();
        $body = (string) $authResponse->getBody();
        if ($statusCode !== 200) {
            $this->logger->error('An error occur during the login request : auth request failed', [
                'uri' => $authUri,
                'response_code' => $statusCode,
                'response_content' => $this->formatForLog($body),
            ]);

            return false;
        }

        $matches = [];
        $pattern = '/http:\/\/.*(?=\/Index.htm)/';
        preg_match($pattern, $body, $matches);

        if (\count($matches) < 1) {
            $this->logger->error('An error occur during the login process : no sessions matched', [
                'uri' => $authUri,
                'matching_pattern' => $pattern,
                'response_content' => $this->formatForLog($body),
            ]);

            return false;
        }

        $hostUri = reset($matches);
        $smsClient = new Client([
            'base_uri' => $hostUri,
            'cookies' => $cookieJar,
            'timeout' => 10,
        ]);
        $now = new \DateTime();
        $sendTime = $now->format('Y,m,d,H,i,s');

        $sms = [
            'module' => 'message',
            'action' => 3,
            'sendMessage' => [
                'to' => $phoneNumber,
                'textContent' => $message,
                'sendTime' => $sendTime,
            ],
        ];
        $smsStringify = \json_encode($sms);

        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
            'Referer' => $hostUri . '_lte_SmsNewMessageCfgRpm.htm',
        ];
        $smsRequestUri = $hostUri . '/lteWebCfg';
        $smsRequest = new Request('POST', $smsRequestUri, $headers, $smsStringify);

        $smsResponse = null;

        try {
            $smsResponse = $smsClient->send($smsRequest);
        } catch (GuzzleException $e) {
            $this->logger->error('An error occur during the sending process : request was not sent', [
                'uri' => $smsRequestUri,
                'exception' => $e,
            ]);
        }

        $smsResponseBody = (string) $smsResponse->getBody();
        if ($smsResponse->getStatusCode() !== 200) {
            $this->logger->error('An error occur during the sending process', [
                'uri' => $smsRequestUri,
                'sms' => $this->formatForLog($smsStringify),
                'response_code' => $smsResponse->getStatusCode(),
                'response_content' => $this->formatForLog($smsResponseBody),
            ]);

            return false;
        }

        $smsResponseBodyDecoded = \json_decode($smsResponseBody, true);

        $sent = key_exists('result', $smsResponseBodyDecoded) && $smsResponseBodyDecoded['result'] === 0;

        if ($sent) {
            $this->logger->notice('An sms was successfuly sent', [
                'sms' => $this->formatForLog($smsStringify),
            ]);
        }

        return $sent;
    }
}
