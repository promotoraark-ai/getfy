<?php

namespace Tests\Unit\Gateways;

use App\Gateways\Stripe\StripeDriver;
use Tests\TestCase;

class StripeDriverTest extends TestCase
{
    public function test_it_sends_payment_method_types_card_when_return_url_is_absent(): void
    {
        $capturedParams = null;
        $driver = new class($capturedParams) extends StripeDriver
        {
            public function __construct(private ?array &$capturedParams) {}

            protected function makeStripeClient(string $secret): \Stripe\StripeClient
            {
                return new class($this->capturedParams) extends \Stripe\StripeClient
                {
                    public function __construct(private ?array &$capturedParams) {}

                    public function __get($name)
                    {
                        if ($name !== 'paymentIntents') {
                            return parent::__get($name);
                        }

                        return new class($this->capturedParams)
                        {
                            public function __construct(private ?array &$capturedParams) {}

                            public function create(array $params): object
                            {
                                $this->capturedParams = $params;
                                return (object) ['id' => 'pi_test_1', 'status' => 'succeeded'];
                            }
                        };
                    }
                };
            }
        };

        $result = $driver->createCardPayment(
            ['secret_key' => 'sk_test_123'],
            10.99,
            ['name' => 'John Doe', 'document' => '12345678900', 'email' => 'john@example.com'],
            'order_1',
            ['payment_token' => 'pm_123']
        );

        $this->assertIsArray($capturedParams);
        $this->assertArrayNotHasKey('return_url', $capturedParams);
        $this->assertSame(['card'], $capturedParams['payment_method_types'] ?? null);
        $this->assertTrue((bool) ($capturedParams['confirm'] ?? false));
        $this->assertSame('pi_test_1', $result['transaction_id']);
        $this->assertSame('paid', $result['status']);
    }

    public function test_it_sends_return_url_when_provided(): void
    {
        $capturedParams = null;
        $driver = new class($capturedParams) extends StripeDriver
        {
            public function __construct(private ?array &$capturedParams) {}

            protected function makeStripeClient(string $secret): \Stripe\StripeClient
            {
                return new class($this->capturedParams) extends \Stripe\StripeClient
                {
                    public function __construct(private ?array &$capturedParams) {}

                    public function __get($name)
                    {
                        if ($name !== 'paymentIntents') {
                            return parent::__get($name);
                        }

                        return new class($this->capturedParams)
                        {
                            public function __construct(private ?array &$capturedParams) {}

                            public function create(array $params): object
                            {
                                $this->capturedParams = $params;
                                return (object) ['id' => 'pi_test_2', 'status' => 'succeeded'];
                            }
                        };
                    }
                };
            }
        };

        $result = $driver->createCardPayment(
            ['secret_key' => 'sk_test_123'],
            10.99,
            ['name' => 'John Doe', 'document' => '12345678900', 'email' => 'john@example.com'],
            'order_2',
            [
                'payment_token' => 'pm_123',
                'return_url' => 'https://example.com/return',
            ]
        );

        $this->assertIsArray($capturedParams);
        $this->assertSame('https://example.com/return', $capturedParams['return_url'] ?? null);
        $this->assertArrayNotHasKey('payment_method_types', $capturedParams);
        $this->assertTrue((bool) ($capturedParams['confirm'] ?? false));
        $this->assertSame('pi_test_2', $result['transaction_id']);
        $this->assertSame('paid', $result['status']);
    }
}
