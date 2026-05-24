<?php

namespace Tests\Unit;

use App\Gateways\Spacepag\SpacepagDriver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SpacepagDriverTest extends TestCase
{
    public function test_detect_auth_mode_pk_sk(): void
    {
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (str_contains($request->url(), '/organizations/balance')
                && $request->header('X-API-Key')[0] === 'pk_test'
                && ($request->header('X-API-Secret')[0] ?? null) === 'sk_test') {
                return Http::response(['success' => true, 'data' => ['balance' => 1, 'currency' => 'BRL']], 200);
            }

            return Http::response(['error' => true, 'message' => 'Unauthorized'], 401);
        });

        $driver = new SpacepagDriver;
        $mode = $driver->detectAuthMode([
            'public_key' => 'pk_test',
            'secret_key' => 'sk_test',
        ]);

        $this->assertSame('pk_sk', $mode);
    }

    public function test_create_pix_uses_detected_auth_mode(): void
    {
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if ($request->method() === 'POST' && str_contains($request->url(), '/payments/transactions')) {
                $this->assertSame('sk_test_123', $request->header('X-API-Key')[0] ?? null);

                return Http::response([
                    'success' => true,
                    'data' => [
                        'id' => 'txn_from_api',
                        'transactionId' => 'txn_from_api',
                        'status' => 'PENDING',
                        'pix' => [
                            'qrCode' => [
                                'emv' => '00020126860014br.gov.bcb.pix',
                                'image' => 'data:image/png;base64,AAA',
                            ],
                        ],
                    ],
                ], 201);
            }
            if (str_contains($request->url(), '/organizations/balance')) {
                return Http::response(['success' => true, 'data' => ['balance' => 1]], 200);
            }

            return Http::response(['message' => 'Unauthorized'], 401);
        });

        $driver = new SpacepagDriver;
        $result = $driver->createPixPayment(
            [
                'public_key' => 'pk_other',
                'secret_key' => 'sk_test_123',
                'auth_mode' => 'sk',
            ],
            10.5,
            [
                'name' => 'João',
                'document' => '123.456.789-01',
                'email' => 'j@example.com',
                'phone' => '11987654321',
            ],
            '99',
            'https://example.com/hook'
        );

        $this->assertSame('txn_from_api', $result['transaction_id']);
    }

    public function test_create_pix_accepts_openapi_shape_with_id_only(): void
    {
        Http::fake([
            'https://api.spacepag.com/v1/payments/transactions' => Http::response([
                'success' => true,
                'data' => [
                    'id' => 'txn_690aaf8f9acbae5e44e465af817e5',
                    'external_id' => 'eb13a725-3105-4c43-9d7d-64ce2840f24e',
                    'referenceCode' => 'REF101130',
                    'status' => 'PENDING',
                    'pix' => [
                        'qrCode' => [
                            'emv' => '00020126860014br.gov.bcb.pix',
                        ],
                    ],
                ],
            ], 201),
        ]);

        $driver = new SpacepagDriver;
        $result = $driver->createPixPayment(
            ['secret_key' => 'sk_test', 'auth_mode' => 'sk'],
            100.0,
            [
                'name' => 'Maria',
                'document' => '12345678901',
                'email' => 'm@example.com',
                'phone' => '11987654321',
            ],
            '42',
            'https://example.com/hook'
        );

        $this->assertSame('txn_690aaf8f9acbae5e44e465af817e5', $result['transaction_id']);
        $this->assertSame('00020126860014br.gov.bcb.pix', $result['copy_paste']);
    }

    public function test_create_pix_parses_spacepag_production_response_shape(): void
    {
        $fixture = [
            'success' => true,
            'data' => [
                'id' => 'txn_6a0a1dd2d0fdaae9db6be2fd8a45b',
                'external_id' => 'txn_6a0a1dd34a4d4d1bb18130dabed4e',
                'referenceCode' => 'REF010097',
                'status' => 'PENDING',
                'amount' => 15,
                'currency' => 'BRL',
                'paymentMethod' => 'pix',
                'pix' => [
                    'qrCode' => [
                        'emv' => '00020126870014br.gov.bcb.pix2565qrcode.fyhub.com.br/qr/v3/at/test',
                        'image' => 'https://api.spacepag.com/v1/payments/qrcode?transaction_id=txn_6a0a1dd2d0fdaae9db6be2fd8a45b',
                    ],
                ],
                'metadata' => [
                    'order_id' => '107',
                ],
            ],
        ];

        Http::fake([
            'https://api.spacepag.com/v1/payments/transactions' => Http::response($fixture, 200),
        ]);

        $driver = new SpacepagDriver;
        $result = $driver->createPixPayment(
            ['secret_key' => 'sk_test', 'auth_mode' => 'sk'],
            15.0,
            [
                'name' => 'Consumidor',
                'document' => '35401338287',
                'email' => 'getfycloud@gmail.com',
                'phone' => '91985134037',
            ],
            '107',
            ''
        );

        $this->assertSame('txn_6a0a1dd2d0fdaae9db6be2fd8a45b', $result['transaction_id']);
        $this->assertStringStartsWith('000201', $result['copy_paste']);
        $this->assertStringContainsString('api.spacepag.com/v1/payments/qrcode', $result['qrcode']);
    }

    public function test_create_pix_recovers_from_empty_body_and_location_header(): void
    {
        Http::fake([
            'https://api.spacepag.com/v1/payments/transactions' => Http::response(
                '',
                201,
                ['Location' => 'https://api.spacepag.com/v1/payments/transactions/txn_from_location']
            ),
            'https://api.spacepag.com/v1/payments/transactions/txn_from_location' => Http::response([
                'success' => true,
                'data' => [
                    'id' => 'txn_from_location',
                    'status' => 'PENDING',
                    'pix' => [
                        'qrCode' => [
                            'emv' => '000201fromget',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $driver = new SpacepagDriver;
        $result = $driver->createPixPayment(
            ['secret_key' => 'sk_test', 'auth_mode' => 'sk'],
            25.0,
            [
                'name' => 'Teste',
                'document' => '12345678901',
                'email' => 't@example.com',
                'phone' => '11999998888',
            ],
            '77',
            ''
        );

        $this->assertSame('txn_from_location', $result['transaction_id']);
        $this->assertSame('000201fromget', $result['copy_paste']);
    }

    public function test_create_pix_parses_flat_response_without_data_wrapper(): void
    {
        Http::fake([
            'https://api.spacepag.com/v1/payments/transactions' => Http::response([
                'success' => true,
                'id' => 'txn_flat',
                'pix' => [
                    'qrcode' => [
                        'emv' => '000201flat',
                    ],
                ],
            ], 201),
        ]);

        $driver = new SpacepagDriver;
        $result = $driver->createPixPayment(
            ['secret_key' => 'sk_test', 'auth_mode' => 'sk'],
            50.0,
            [
                'name' => 'Ana',
                'document' => '12345678901',
                'email' => 'a@example.com',
                'phone' => '11999998888',
            ],
            '1',
            ''
        );

        $this->assertSame('txn_flat', $result['transaction_id']);
        $this->assertSame('000201flat', $result['copy_paste']);
    }

    public function test_get_transaction_status_maps_approved_to_paid(): void
    {
        Http::fake([
            'https://api.spacepag.com/v1/payments/transactions/txn_x' => Http::response([
                'success' => true,
                'data' => [
                    'id' => 'txn_x',
                    'status' => 'APPROVED',
                ],
            ], 200),
        ]);

        $driver = new SpacepagDriver;
        $status = $driver->getTransactionStatus('txn_x', [
            'secret_key' => 'sk_test',
            'auth_mode' => 'sk',
        ]);

        $this->assertSame('paid', $status);
    }
}
