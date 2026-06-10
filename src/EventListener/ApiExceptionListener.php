<?php

namespace App\EventListener;

use Doctrine\DBAL\Exception\DriverException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

#[AsEventListener(event: 'kernel.exception', priority: 10)]
class ApiExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        // 1. Check if the error is a low-level Database/Driver crash
        if ($exception instanceof DriverException || str_contains($exception->getMessage(), 'SQLSTATE')) {
            $response = new JsonResponse([
                'title' => 'Service Unavailable',
                'detail' => 'The data subsystem is currently initializing or undergoing maintenance. Please try again shortly.',
                'code' => 503
            ], 503);

            $event->setResponse($response);
            return;
        }

        // 2. Check if it's an Authentication layer drop out
        if ($exception instanceof AuthenticationException) {
            $response = new JsonResponse([
                'title' => 'Unauthorized Access',
                'detail' => 'Your authentication credentials are identity invalid, missing, or expired.',
                'code' => 401
            ], 401);

            $event->setResponse($response);
            return;
        }

        // 3. Fallback wrapper for general explicit HTTP errors (like 404, 403, 405)
        if ($exception instanceof HttpExceptionInterface) {
            $response = new JsonResponse([
                'title' => 'An error occurred',
                'detail' => $exception->getMessage(),
                'code' => $exception->getStatusCode()
            ], $exception->getStatusCode());

            $event->setResponse($response);
            return;
        }

        // 4. Ultimate catch-all to prevent raw code trace leaks for unhandled server issues (500)
        // Only run this safety net if we aren't in strict local development mode
        if ($_ENV['APP_ENV'] !== 'dev' || $_ENV['APP_ENV'] !== 'prod') {
            $response = new JsonResponse([
                'title' => 'Internal Server Error',
                'detail' => 'A generic server exception processing routine error occurred.'.$_ENV['APP_ENV'],
                'code' => 500
            ], 500);

            $event->setResponse($response);
        }
    }
}
