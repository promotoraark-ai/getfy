<?php

return [
    /*
    |--------------------------------------------------------------------------
    | ArkGateway Configuration
    | Centraliza gateways, links, splits e regras de acesso.
    |--------------------------------------------------------------------------
    */

    // Gateways permitidos (whitelist)
    'allowed_gateways' => ['asaas', 'cajupay'],

    // Links de signup obrigatórios
    'signup_links' => [
        'asaas' => 'https://www.asaas.com/r/2617ea23-f001-4a8e-8413-2eb1a5f5145c',
        'cajupay' => 'https://cajupay.com.br/registro?ref=596d6c91fe',
    ],

    // Asaas: walletId e splits
    'asaas_wallet_id' => '980e0211-b0a8-4c95-96ce-f92539d7c85e',
    'asaas_splits' => [
        'pix' => 0.50,        // R$ 0,50
        'boleto' => 1.00,     // R$ 1,00
        'card' => 0.017,      // 1,70%
    ],

    // CajuPay: split_id fixo
    'cajupay_split_id' => '827db98c-ac29-4733-bd1f-d7fa2ac02640',
];
