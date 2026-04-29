<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DemoHealthCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_health_command_checks_level_35_prerequisites(): void
    {
        config([
            'payroll.anchor.script' => base_path('../onchain/scripts/anchor-attest.js'),
            'payroll.anchor.wallet_path' => base_path('artisan'),
            'payroll.anchor.program_id' => 'AsterProgram111111111111111111111111111111111',
            'payroll.confidential.rpc_url' => 'http://solana-rpc.test',
            'payroll.confidential.token_program_id' => 'TokenzQdBNbLqP5VEhdkAS6EPFLC1PHnBqCXEpPxuEb',
        ]);

        Http::fake([
            'http://solana-rpc.test' => Http::sequence()
                ->push(['jsonrpc' => '2.0', 'result' => 'ok'], 200)
                ->push(['jsonrpc' => '2.0', 'result' => ['value' => ['executable' => true]]], 200)
                ->push(['jsonrpc' => '2.0', 'result' => ['value' => ['executable' => true]]], 200),
        ]);

        $this->artisan('payroll:demo-health')
            ->expectsOutputToContain('PASS Laravel DB migrations')
            ->expectsOutputToContain('PASS Local confidential validator reachability')
            ->expectsOutputToContain('PASS Token-2022 program availability')
            ->expectsOutputToContain('PASS Aster program availability')
            ->assertExitCode(0);
    }
}
