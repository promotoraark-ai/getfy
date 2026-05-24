<?php

namespace Plugins\WhiteLabel;

use Illuminate\Database\Eloquent\Model;

class WhiteLabelSetting extends Model
{
    protected $table = 'white_label_settings';

    protected $fillable = [
        'tenant_id',
        'data',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }
}
