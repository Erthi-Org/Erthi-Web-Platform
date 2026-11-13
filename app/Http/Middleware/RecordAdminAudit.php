<?php

namespace App\Http\Middleware;

use App\Support\AdminAudit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class RecordAdminAudit
{
    public function __construct(private readonly AdminAudit $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! $request->isMethodSafe()) {
            try {
                $this->audit->record(
                    (int) $request->user()->getAuthIdentifier(),
                    class_basename($request->route()->getActionName()),
                    $request->method(),
                    $response->getStatusCode(),
                    $request->attributes->get('audit.account', []),
                );
            } catch (Throwable $error) {
                report($error);
            }
        }

        return $response;
    }
}
