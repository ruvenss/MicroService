<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Filters\Stealth;
use CodeIgniter\Debug\BaseExceptionHandler;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Renders every uncaught exception as a neutral RFC 9457 problem+json response.
 *
 * This is the last line of stealth defence: without it, a 500 would render
 * CodeIgniter's HTML error page (in production) or a full stack trace (in
 * development), either of which instantly reveals the engine. Here, callers and
 * probes get the same shape as every other error and learn nothing.
 */
final class ApiExceptionHandler extends BaseExceptionHandler implements ExceptionHandlerInterface
{
    public function handle(
        Throwable $exception,
        RequestInterface $request,
        ResponseInterface $response,
        int $statusCode,
        int $exitCode
    ): void {
        $this->prepare($exception, $response, $statusCode)->send();
    }

    /**
     * Build the hardened problem+json response without sending it.
     * Separated from handle() so it can be unit-tested without emitting output.
     */
    public function prepare(Throwable $exception, ResponseInterface $response, int $statusCode): ResponseInterface
    {
        $status = ($statusCode >= 400 && $statusCode <= 599) ? $statusCode : 500;

        // Outside production, surface only the message (never class/file/trace)
        // so developers can debug without leaking the engine. In production,
        // nothing beyond the generic title is disclosed.
        $detail = ENVIRONMENT !== 'production' ? ($exception->getMessage() ?: null) : null;

        $body = ProblemDetails::make($status, $detail, [
            'requestId' => RequestContext::id(),
        ]);

        Stealth::harden($response);

        return $response
            ->setStatusCode($status)
            ->setBody((string) json_encode($body))
            ->setContentType('application/problem+json')
            ->setHeader('X-Request-Id', RequestContext::id());
    }
}
