<?php

declare(strict_types=1);

namespace App\Filters;

use App\Libraries\RequestContext;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Establishes a correlation id for every request. Adopts a well-formed inbound
 * X-Request-Id (so a caller / n8n can supply its own trace id) or mints one,
 * then echoes it on the response.
 */
class RequestId implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $inbound = $request->getHeaderLine('X-Request-Id') ?: null;
        RequestContext::adopt($inbound);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $response->setHeader('X-Request-Id', RequestContext::id());

        return $response;
    }
}
