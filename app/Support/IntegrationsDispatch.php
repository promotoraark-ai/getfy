<?php

namespace App\Support;

use Illuminate\Support\Str;

class IntegrationsDispatch
{
    /**
     * Meta CAPI, Utmify paid, etc.: rodar após a resposta HTTP (sem depender de worker)
     * em fila sync/database ou com INTEGRATIONS_DISPATCH_SYNC=true.
     */
    public static function shouldDispatchAfterResponse(): bool
    {
        if (config('getfy.integrations.dispatch_after_response') === false) {
            return false;
        }

        $default = (string) config('queue.default', 'sync');
        if ($default === 'sync' || $default === 'database') {
            return true;
        }

        $v = (string) env('INTEGRATIONS_DISPATCH_SYNC', '');
        if ($v !== '' && in_array(Str::lower(trim($v)), ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        return false;
    }
}
