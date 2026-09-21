<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\RateLimiter;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('login:127.0.0.1');
        $this->forgetAuthGuards();
    }

    protected function forgetAuthGuards(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /**
     * @return array{name: string, email: string, cpf: string, phone_number: string, password: string, password_confirmation: string}
     */
    protected function validRegistrationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Usuário Teste',
            'email' => 'usuario@teste.com',
            'cpf' => '12345678901',
            'phone_number' => '11999999999',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ], $overrides);
    }
}
