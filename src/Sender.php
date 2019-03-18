<?php

declare(strict_types=1);

namespace SmsSender;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

class Sender implements LoggerAwareInterface
{
    use LogFormatterTrait;
    use LoggerAwareTrait;

    private $host;
    private $userName;
    private $password;

    public function __construct(string $host, string $userName, string $password)
    {
        $this->host = $host;
        $this->userName = $userName;
        $this->password = $password;
    }

    public function send(string $phoneNumber, string $message, bool $rebootOnFail = false): bool
    {
        $authenticator = new Authenticator($this->host, $this->userName, $this->password);
        $client = $authenticator->auth();

        if ($client === false) {
            return false;
        }

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

        $hostUri = $client->getConfig('base_uri');
        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
            'Referer' => $hostUri . '_lte_SmsNewMessageCfgRpm.htm',
        ];
        $smsRequestUri = $hostUri . '/lteWebCfg';
        $smsRequest = new Request('POST', $smsRequestUri, $headers, $smsStringify);

        $smsResponse = null;

        try {
            $smsResponse = $client->send($smsRequest);
        } catch (GuzzleException $e) {
            $this->logger->error('An error occur during the sending process : request was not sent', [
                'uri' => $smsRequestUri,
                'exception' => $e,
            ]);

            if ($rebootOnFail) {
                $rebootController = new RebootController();
                $rebootController->rebootRouter($client);
            }

            return false;
        }

        $smsResponseBody = (string) $smsResponse->getBody();
        if ($smsResponse->getStatusCode() !== 200) {
            $this->logger->error('An error occur during the sending process', [
                'uri' => $smsRequestUri,
                'sms' => $this->formatForLog($smsStringify),
                'response_code' => $smsResponse->getStatusCode(),
                'response_content' => $this->formatForLog($smsResponseBody),
            ]);

            if ($rebootOnFail) {
                $rebootController = new RebootController();
                $rebootController->rebootRouter($client);
            }

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
