<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Product;
use App\Models\Hold;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

class FlashSaleTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function parallel_hold_attempts_do_not_oversell()
    {
        $product = Product::factory()->create(['stock' => 1]);

        // Try holding 1 unit twice at same time
        $first = $this->postJson('/api/holds', ['product_id' => $product->id, 'qty' => 1]);
        $second = $this->postJson('/api/holds', ['product_id' => $product->id, 'qty' => 1]);

        // One should succeed, the other should fail
        $this->assertTrue(
            ($first->status() === 201 && $second->status() === 409) ||
            ($first->status() === 409 && $second->status() === 201)
        );

        // There must NEVER be 2 active holds for 1 stock
        $activeHolds = Hold::where('product_id', $product->id)->where('used', false)->count();
        $this->assertEquals(1, $activeHolds);
    }

    /** @test */
    public function expired_hold_returns_stock()
    {
        $product = Product::factory()->create(['stock' => 2]);

        // Create hold
        $hold = Hold::factory()->create([
            'product_id' => $product->id,
            'qty' => 2,
            'used' => false,
            'expires_at' => now()->subMinutes(10), // expired
        ]);

        // Run expiry command
        $this->artisan('holds:release-expired')->run();

        // Refresh product
        $product->refresh();

        // Stock should be fully available again
        $this->assertEquals(2, $product->available_stock);

        // Hold must be marked as used (expired)
        $this->assertTrue(Hold::find($hold->id)->used);
    }

    /** @test */
    public function webhook_idempotency()
    {
        $product = Product::factory()->create(['stock' => 2]);

        $order = Order::factory()->create([
            'product_id' => $product->id,
            'qty' => 1,
            'payment_status' => 'pending',
        ]);

        $payload = [
            'order_id' => $order->id,
            'payment_status' => 'paid',
            'signature' => 'xxx-yyy',
        ];

        // First webhook
        $first = $this->postJson('/api/payments/webhook', $payload);
        $first->assertStatus(200);

        // Stock after first payment = 1
        $this->assertEquals(1, $product->refresh()->stock);

        // Second webhook repeated
        $second = $this->postJson('/api/payments/webhook', $payload);
        $second->assertStatus(200); // Should not fail

        // Stock must NOT change again
        $this->assertEquals(1, $product->refresh()->stock);
    }

    /** @test */
    public function webhook_arrives_before_order()
    {
        $product = Product::factory()->create(['stock' => 5]);

        $payload = [
            'order_id' => 9999,   // Order doesn't exist yet
            'payment_status' => 'paid',
            'signature' => 'xxx',
        ];

        // Webhook arrives BEFORE order
        $early = $this->postJson('/api/payments/webhook', $payload);
        $early->assertStatus(202); // accepted but pending

        // Later order created
        $order = Order::factory()->create([
            'id' => 9999,
            'product_id' => $product->id,
            'qty' => 1,
            'payment_status' => 'pending',
        ]);

        // Re-trigger webhook
        $trigger = $this->postJson('/api/payments/webhook', $payload);
        $trigger->assertStatus(200);

        // Stock must decrease
        $this->assertEquals(4, $product->refresh()->stock);
    }
}
