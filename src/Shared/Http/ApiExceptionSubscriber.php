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
    /**
     * Only these InvalidArgumentException messages are safe to echo to clients.
     *
     * @var list<string>
     */
    private const PUBLIC_INVALID_ARGUMENT_MESSAGES = [
        'Message cannot be empty.',
    ];

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

        if ($exception instanceof HttpExceptionInterface) {
            $statusCode = $exception->getStatusCode();
            $publicMessage = $statusCode >= 500 ? 'Internal server error.' : $exception->getMessage();
        } elseif ($exception instanceof \InvalidArgumentException) {
            $statusCode = 400;
            $message = $exception->getMessage();
            $publicMessage = in_array($message, self::PUBLIC_INVALID_ARGUMENT_MESSAGES, true)
                ? $message
                : 'Invalid request.';
        } else {
            $statusCode = 500;
            $publicMessage = 'Internal server error.';
        }

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
