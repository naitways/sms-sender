<?php

namespace SmsSender;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

class StatusPage implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private $host;
    private $userName;
    private $password;

    public function __construct(string $host, string $userName, string $password, LoggerInterface $logger)
    {
        $this->host = $host;
        $this->userName = $userName;
        $this->password = $password;
        $this->setLogger($logger);
    }

    private function authenticate(): ?Client
    {
        $authenticator = new Authenticator($this->host, $this->userName, $this->password, $this->logger);
        $client = $authenticator->auth();

        if ($client === null) {
            $this->logger->error('An error occurs during the authentication process');
        }

        return $client;
    }

    public function connectionStatus(): ?string
    {
        $client = $this->authenticate();
        if ($client === null) {
            return null;
        }

        /** @var \GuzzleHttp\Psr7\Uri $hostUri */
        $hostUri = $client->getConfig('base_uri');

        $uri = $hostUri->getPath() . '/lteWebCfg';
        $headers = [
            'X-Requested-With' => 'XMLHttpRequest',
            'Referer' => $hostUri->__toString() . '/StatusRpm.htm',
            'DNT' => 1,
            'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8'
        ];

        $request = new Request('POST', $uri, $headers, '{"module": "status", "action": 0}');
        $response = $client->send($request);

        $body = $response->getBody()->getContents();
        $content = json_decode($body, true);

        if (!key_exists('wan', $content) || !key_exists('connectStatus', $content['wan'])) {
            return false;
        }

        return $content['wan']['connectStatus'] === 1;
    }
}
