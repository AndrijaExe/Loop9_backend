<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => 'onException',
        ];
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with((string) $request->getPathInfo(), '/api/')) {
            return;
        }

        $exception = $event->getThrowable();
        $statusCode = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;

        $publicMessage = $statusCode >= 500 ? 'Internal server error.' : $exception->getMessage();

        $this->logger->error('API request failed.', [
            'statusCode' => $statusCode,
            'path' => $request->getPathInfo(),
            'exception' => $exception,
        ]);

        $event->setResponse(new JsonResponse([
            'error' => [
                'message' => $publicMessage,
                'code' => $statusCode >= 500 ? 'INTERNAL_ERROR' : 'REQUEST_ERROR',
            ],
        ], $statusCode));
    }
}
