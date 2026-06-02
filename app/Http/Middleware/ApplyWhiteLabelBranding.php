<?php

namespace App\Http\Middleware;

use App\Support\WhiteLabelBranding;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplyWhiteLabelBranding
{
    public function handle(Request $request, Closure $next): Response
    {
        WhiteLabelBranding::apply($request);

        return $next($request);
    }
}
