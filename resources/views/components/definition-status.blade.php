@props(['status'])

@php
    $classes = match ($status) {
        \App\Enums\ShopifyDefinitionStatus::Unsupported => 'border-line text-ink-subtle/60',
        \App\Enums\ShopifyDefinitionStatus::Disabled => 'border-line-strong text-ink-subtle',
        \App\Enums\ShopifyDefinitionStatus::Missing => 'border-amber-400/30 bg-amber-400/5 text-amber-300',
        \App\Enums\ShopifyDefinitionStatus::Error => 'border-red-500/30 bg-red-500/5 text-red-300',
        \App\Enums\ShopifyDefinitionStatus::Created => 'border-brand/30 bg-brand/5 text-brand',
    };
@endphp

<span {{ $attributes->class(['badge', $classes]) }}>{{ $status->label() }}</span>
