<?php

namespace Tests\Feature;

use Tests\TestCase;

class HelloWorldTest extends TestCase
{
    public function test_hello_endpoint_returns_hello_world(): void
    {
        $response = $this->getJson('/api/hello');

        $response
            ->assertOk()
            ->assertExactJson([
                'message' => 'Hello World',
            ]);
    }
}
