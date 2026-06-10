<?php

return [
    /*
    |--------------------------------------------------------------------------
    | ArkGateway — Configuração centralizada
    |--------------------------------------------------------------------------
    | Apenas Asaas e CajuPay são permitidos.
    | Splits, URLs de signup e walletIds são gerenciados aqui.
    */

    'allowed_gateways' => ['asaas', 'cajupay'],

    'branding' => [
        'name' => 'ArkGateway',
        'description' => 'Gateway de pagamentos integrado',
    ],

    /*
    |--------------------------------------------------------------------------
    | Asaas
    |--------------------------------------------------------------------------
    */
    'asaas' => [
        'signup_url' => 'https://www.asaas.com/r/2617ea23-f001-4a8e-8413-2eb1a5f5145c',
        'wallet_id' => '980e0211-b0a8-4c95-96ce-f92539d7c85e',
        'splits' => [
            'pix' => [
                'type' => 'fixed_amount',
                'value_cents' => 50,
                'description' => 'Tarifa Asaas PIX',
            ],
            'boleto' => [
                'type' => 'fixed_amount',
                'value_cents' => 100,
                'description' => 'Tarifa Asaas Boleto',
            ],
            'card' => [
                'type' => 'percentage',
                'value' => 1.70,
                'description' => 'Tarifa Asaas Cartão',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | CajuPay
    |--------------------------------------------------------------------------
    */
    'cajupay' => [
        'signup_url' => 'https://cajupay.com.br/registro?ref=596d6c91fe',
        'split_id' => '827db98c-ac29-4733-bd1f-d7fa2ac02640',
        'splits' => [
            'pix' => [
                'type' => 'split_id',
                'split_id' => '827db98c-ac29-4733-bd1f-d7fa2ac02640',
                'description' => 'Split CajuPay PIX',
            ],
            'card' => [
                'type' => 'split_id',
                'split_id' => '827db98c-ac29-4733-bd1f-d7fa2ac02640',
                'description' => 'Split CajuPay Cartão',
            ],
            'apple_pay' => [
                'type' => 'split_id',
                'split_id' => '827db98c-ac29-4733-bd1f-d7fa2ac02640',
                'description' => 'Split CajuPay Apple Pay',
            ],
            'google_pay' => [
                'type' => 'split_id',
                'split_id' => '827db98c-ac29-4733-bd1f-d7fa2ac02640',
                'description' => 'Split CajuPay Google Pay',
            ],
        ],
    ],
];
