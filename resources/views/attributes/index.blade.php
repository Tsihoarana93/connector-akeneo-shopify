<x-layouts.app title="Attributes">
    <div class="flex flex-wrap items-end justify-between gap-6">
        <div class="flex max-w-2xl flex-col gap-3">
            <span class="label-mono">Akeneo → Shopify metafields</span>
            <h1 class="page-title">Akeneo attributes</h1>
            <p class="text-ink-muted">
                Enabled attributes are sent to Shopify as <code class="font-mono text-sm text-ink">{{ \App\Models\AkeneoAttribute::SHOPIFY_NAMESPACE }}.*</code> product metafields once their definition exists.
            </p>
            <p class="flex flex-wrap items-center gap-2 text-sm text-ink-subtle">
                <span class="label-mono">Last retrieved</span>
                @if ($lastSyncedAt)
                    <time datetime="{{ $lastSyncedAt->toIso8601String() }}" class="font-mono text-xs text-ink">{{ $lastSyncedAt->format('Y-m-d H:i:s') }}</time>
                    <span>({{ $lastSyncedAt->diffForHumans() }})</span>
                @else
                    <span>never</span>
                @endif
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <form method="POST" action="{{ route('attributes.sync') }}">
                @csrf
                <button type="submit" class="btn-secondary">Update from Akeneo</button>
            </form>
            <form method="POST" action="{{ route('attributes.definitions.store') }}">
                @csrf
                <button type="submit" @disabled($missingDefinitions === 0) class="btn-primary">
                    Create missing definitions ({{ $missingDefinitions }})
                </button>
            </form>
        </div>
    </div>

    @if ($errors->any())
        <div class="alert-error">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <section class="flex flex-col gap-4">
        <form method="GET" action="{{ route('attributes.index') }}" class="flex flex-wrap items-center gap-3">
            <input type="search" name="search" value="{{ $search }}" placeholder="Code or label" class="field w-56">
            <select name="filter" class="field">
                <option value="">All attributes</option>
                @foreach (\App\Http\Controllers\AkeneoAttributeController::FILTERS as $value => $label)
                    <option value="{{ $value }}" @selected($filter === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn-secondary">Filter</button>
            <span class="label-mono ml-auto">{{ $attributes->count() }} attributes</span>
        </form>

        <div class="card overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Attribute</th>
                        <th>Akeneo type</th>
                        <th>Products</th>
                        <th>Sample values</th>
                        <th>Shopify</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($attributes as $attribute)
                        @php($attributeUsage = $usage[$attribute->code] ?? ['products' => 0, 'samples' => []])
                        <tr>
                            <td>
                                <span class="block font-medium">{{ $attribute->label() }}</span>
                                <code class="font-mono text-xs text-ink-subtle">{{ $attribute->code }}</code>
                            </td>
                            <td class="font-mono text-xs text-ink-muted">
                                {{ \Illuminate\Support\Str::after($attribute->type, 'pim_catalog_') }}
                                @if ($attribute->localizable) <span class="block text-ink-subtle" title="Localizable">per locale</span> @endif
                                @if ($attribute->scopable) <span class="block text-ink-subtle" title="Scopable">per channel</span> @endif
                            </td>
                            <td class="font-mono text-xs">{{ $attributeUsage['products'] }}</td>
                            <td class="max-w-xs truncate text-ink-muted" title="{{ collect($attributeUsage['samples'])->map(fn ($data) => $attribute->formatValue($data))->implode(' | ') }}">
                                {{ collect($attributeUsage['samples'])->take(3)->map(fn ($data) => \Illuminate\Support\Str::limit((string) $attribute->formatValue($data), 40))->implode(' · ') ?: '—' }}
                            </td>
                            <td>
                                <x-definition-status :status="$attribute->definitionStatus()" />
                                @if ($attribute->hasDefinition() && ! $attribute->shopify_enabled)
                                    <span class="mt-1 block text-xs text-ink-subtle">not sent (disabled)</span>
                                @endif
                            </td>
                            <td class="text-right"><a href="{{ route('attributes.show', $attribute) }}" class="link font-mono text-xs uppercase">Details</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-10 text-center text-ink-subtle">No attributes. Click "Update from Akeneo" to import them.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.app>
