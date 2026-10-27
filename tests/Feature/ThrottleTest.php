<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploads_are_limited_to_twenty_per_minute(): void
    {
        // an empty body fails validation, which still counts as a request
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/files')->assertStatus(422);
        }

        $this->postJson('/files')->assertStatus(429);
    }

    public function test_deletes_are_limited_to_thirty_per_minute(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->deleteJson('/files/999999')->assertStatus(404);
        }

        $this->deleteJson('/files/999999')->assertStatus(429);
    }
}
