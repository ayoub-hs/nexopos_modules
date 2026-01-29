<?php

namespace Modules\NsSpecialCustomer\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSpecialCustomerPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (!ns()->allowedTo($permission)) {
            return response()->json([
                'status' => 'error',
                'message' => __('You do not have permission to perform this action.')
            ], 403);
        }

        return $next($request);
    }
}

