<?php

declare(strict_types=1);

namespace App\Controllers\Debug;

use App\Controllers\BaseController;
use App\Libraries\Debug\DebugSnapshot;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * `/ms_debug` — the development-only realtime debug & information dashboard.
 *
 * A framework-level dev aid (ARCHITECTURE §3), NOT a business feature: it is only
 * routed when ENVIRONMENT === 'development' (see Config\Routes). This controller
 * re-asserts that gate itself so that, even if the route were ever reached in
 * another environment (misconfiguration, a stray registration, worker-mode route
 * reuse), it degrades to the SAME neutral problem+json 404 as any unknown path —
 * the service never reveals that a debug surface exists. This preserves the
 * "never reveal the engine" stealth posture (ARCHITECTURE §18).
 *
 *   GET /ms_debug        → the HTML dashboard shell (self-refreshing).
 *   GET /ms_debug/data   → the JSON snapshot the page polls every REFRESH_MS.
 */
class MsDebug extends BaseController
{
    /** How often (ms) the dashboard re-polls /ms_debug/data. "Realtime" without holding an FPM worker. */
    private const REFRESH_MS = 2000;

    /**
     * The dashboard page. Ships an initial snapshot inline (no first-paint flash),
     * then the embedded script polls the data endpoint for live updates.
     */
    public function index(): string
    {
        $this->guardDevelopmentOnly();

        return view('debug/dashboard', [
            'refreshMs' => self::REFRESH_MS,
            'initial'   => (new DebugSnapshot())->full(),
        ]);
    }

    /**
     * The polled JSON snapshot. `no-store` so a proxy/browser never caches a live
     * frame of service state.
     */
    public function data(): ResponseInterface
    {
        $this->guardDevelopmentOnly();

        return $this->response
            ->setHeader('Cache-Control', 'no-store')
            ->setJSON((new DebugSnapshot())->full());
    }

    /**
     * Refuse anything but development with the neutral 404 override — never a
     * distinct 403, which would itself disclose that the endpoint is real.
     */
    private function guardDevelopmentOnly(): void
    {
        if (ENVIRONMENT !== 'development') {
            throw PageNotFoundException::forPageNotFound();
        }
    }
}
