<?php

namespace SmsSender;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

class RebootController implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    use LogFormatterTrait;

    public function __construct(LoggerInterface $logger)
    {
        $this->setLogger($logger);
    }

    public function rebootRouter(Client $client): bool
    {
        $uri = '/userRpm/SysRebootRpm.htm?Reboot=Reboot';
        try {
            $response = $client->get($uri);
        } catch (GuzzleException $e) {
            $this->logger->error('An error occur during the reboot process : request was not sent', [
                'uri' => $uri,
                'exception' => $e,
            ]);

            return false;
        }

        $body = (string) $response->getBody();
        if ($response->getStatusCode() !== 200) {
            $this->logger->error('An error occur during the reboot process', [
                'uri' => $uri,
                'response_code' => $response->getStatusCode(),
                'response_content' => $this->formatForLog($body),
            ]);

            return false;
        }

        $this->logger->info('Reboot instruction was sent successfuly');

        return true;
    }
}
