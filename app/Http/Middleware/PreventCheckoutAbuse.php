<?php

namespace App\Http\Middleware;

use App\Models\Product;
use App\Services\CheckoutAbuseGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class PreventCheckoutAbuse
{
    public function __construct(
        private readonly CheckoutAbuseGuard $guard
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->guard->isEnabled()) {
            return $next($request);
        }

        if ($this->guard->honeypotTriggered($request)) {
            throw new TooManyRequestsHttpException(60, 'Muitas tentativas. Aguarde e tente novamente.');
        }

        $product = null;
        $productId = $request->input('product_id');
        if (is_string($productId) && $productId !== '') {
            $product = Product::query()->where('id', $productId)->where('is_active', true)->first();
        }

        if ($this->guard->requiresCaptcha($request, $product)) {
            $result = app(\App\Services\TurnstileVerifier::class)->verify($request);
            if (! $result['ok']) {
                $this->guard->markCaptchaRequired($request);
                throw new TooManyRequestsHttpException(120, 'Verificação de segurança necessária. Recarregue a página e tente novamente.');
            }
        }

        $response = $next($request);

        if ($response->isSuccessful() || $response->isRedirection()) {
            $this->guard->clearAttempts($request, $product);
        } else {
            $this->guard->recordAttempt($request, $product);
        }

        return $response;
    }
}
