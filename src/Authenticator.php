<?php

declare(strict_types=1);

namespace SmsSender;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

class Authenticator implements LoggerAwareInterface
{
    use LogFormatterTrait;
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

    public function auth(): ?Client
    {
        $auth = base64_encode($this->userName . ':' . md5($this->password));

        $cookie = new SetCookie();
        $cookie->setName('Authorization');
        $cookie->setValue('Basic ' . $auth);
        $cookie->setPath('/');
        $cookie->setDomain($this->host);

        $cookieJar = new CookieJar();
        $cookieJar->setCookie($cookie);

        $authClient = new Client([
            'base_uri' => 'http://' . $this->host,
            'cookies' => $cookieJar,
        ]);
        $authUri = 'http://' . $this->host . '/userRpm/LoginRpm.htm?Save=save';
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

            return null;
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

            return null;
        }
        $this->logger->info('Successfully authenticated on the router');

        $hostUri = reset($matches);

        $client = new Client([
            'base_uri' => $hostUri,
            'cookies' => $cookieJar,
            'timeout' => 10,
        ]);

        return $client;
    }
}
