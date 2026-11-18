<?php

declare(strict_types=1);

namespace Ivanange\Billing\Gateways\Omnipay;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * PSR-18 client that sends Omnipay's requests through Laravel's Http
 * client: one place for timeouts, and Http::fake() covers every gateway
 * in tests. 4xx/5xx responses are returned, not thrown, as PSR-18 says; a
 * timeout, dropped connection, or a transfer that broke after a 5xx/408
 * started is a network error (the provider may have acted on it).
 */
final class LaravelHttpClient implements ClientInterface
{
    public function __construct(
        private readonly Factory $http,
        private readonly int $timeoutSeconds = 30,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        $body = (string) $request->getBody();
        $pending = $this->http->withHeaders($headers)->timeout($this->timeoutSeconds);

        if ($body !== '') {
            $pending = $pending->withBody($body, $request->getHeaderLine('Content-Type') ?: 'application/json');
        }

        try {
            return $pending->send($request->getMethod(), (string) $request->getUri())->toPsrResponse();
        } catch (RequestException $e) {
            // The transfer broke after the response started. On a 5xx or 408 the
            // provider may still have acted, exactly as on a dropped connection.
            if ($e->response->serverError() || $e->response->status() === 408) {
                throw self::lostInTransit($e, $request);
            }

            throw $e;
        } catch (ConnectionException $e) {
            throw self::lostInTransit($e, $request);
        }
    }

    private static function lostInTransit(Throwable $e, RequestInterface $request): NetworkExceptionInterface
    {
        return new class($e->getMessage(), $request, $e) extends RuntimeException implements NetworkExceptionInterface
        {
            public function __construct(string $message, private readonly RequestInterface $request, Throwable $previous)
            {
                parent::__construct($message, 0, $previous);
            }

            public function getRequest(): RequestInterface
            {
                return $this->request;
            }
        };
    }
}
