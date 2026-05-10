@props([
    'signature',
    'linkClass' => 'break-all font-mono text-xs text-cyan-100 underline underline-offset-4 hover:text-cyan-50',
])

@php
    $signature = trim((string) $signature);
    $explorerUrl = rtrim((string) config('payroll.explorer.url', 'https://explorer.solana.com'), '/');
    $browserRpcUrl = (string) config('payroll.explorer.browser_rpc_url', 'http://localhost:8899/');
    $txUrl = $explorerUrl.'/tx/'.rawurlencode($signature).'?cluster=custom&customUrl='.rawurlencode($browserRpcUrl);
@endphp

@if ($signature !== '')
    <a
        href="{{ $txUrl }}"
        target="_blank"
        rel="noopener noreferrer"
        title="{{ __('ui.actions.open_in_solana_explorer') }}"
        aria-label="{{ __('ui.actions.open_in_solana_explorer') }} {{ $signature }}"
        {{ $attributes->merge(['class' => $linkClass]) }}
    >
        @if ($slot->isEmpty())
            {{ $signature }}
        @else
            {{ $slot }}
        @endif
    </a>
@endif
