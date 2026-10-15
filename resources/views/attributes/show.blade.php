<x-layouts.app :title="$attribute->label()">
    <div class="flex flex-col gap-3">
        <a href="{{ route('attributes.index') }}" class="label-mono transition-colors hover:text-brand">← All attributes</a>
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="page-title">{{ $attribute->label() }}</h1>
            <code class="font-mono text-sm text-ink-subtle">{{ $attribute->code }}</code>
            <x-definition-status :status="$attribute->definitionStatus()" />
        </div>
    </div>

    @if ($errors->any())
        <div class="alert-error">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <section class="flex flex-col gap-3">
        <h2 class="section-title">Akeneo</h2>
        <dl class="card grid gap-6 p-5 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div class="flex flex-col gap-1"><dt class="label-mono">Type</dt><dd><code class="font-mono text-xs">{{ $attribute->type }}</code></dd></div>
            <div class="flex flex-col gap-1"><dt class="label-mono">Group</dt><dd>{{ $attribute->raw_payload['group'] ?? '—' }}</dd></div>
            <div class="flex flex-col gap-1"><dt class="label-mono">Localizable / scopable</dt><dd>{{ $attribute->localizable ? 'yes' : 'no' }} / {{ $attribute->scopable ? 'yes' : 'no' }}</dd></div>
            <div class="flex flex-col gap-1"><dt class="label-mono">Products with a value</dt><dd class="font-mono">{{ $usage['products'] }}</dd></div>
            <div class="flex flex-col gap-1 sm:col-span-2">
                <dt class="label-mono">Labels</dt>
                <dd class="text-ink-muted">{{ collect($attribute->labels ?? [])->map(fn ($label, $locale) => "{$locale}: {$label}")->implode(' · ') ?: '—' }}</dd>
            </div>
            <div class="flex flex-col gap-1 sm:col-span-2">
                <dt class="label-mono">Sample values</dt>
                <dd class="text-ink-muted">
                    @forelse ($usage['samples'] as $data)
                        <span class="block truncate">{{ $attribute->formatValue($data) }}</span>
                    @empty
                        —
                    @endforelse
                </dd>
            </div>
        </dl>
    </section>

    @if ($attribute->options !== null)
        <section class="flex flex-col gap-3">
            <h2 class="section-title">Options <span class="text-ink-subtle">({{ count($attribute->options) }})</span></h2>
            <div class="card overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Label sent to Shopify</th>
                            <th>Other labels</th>
                            <th>Products</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($attribute->options as $optionCode => $labels)
                            <tr>
                                <td><code class="font-mono text-xs">{{ $optionCode }}</code></td>
                                <td>{{ $attribute->optionLabel((string) $optionCode) }}</td>
                                <td class="text-xs text-ink-muted">{{ collect($labels)->filter()->map(fn ($label, $locale) => "{$locale}: {$label}")->implode(' · ') }}</td>
                                <td class="font-mono text-xs">{{ $usage['options'][(string) $optionCode] ?? 0 }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-10 text-center text-ink-subtle">No options.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="flex flex-col gap-3">
        <h2 class="section-title">Shopify</h2>
        <div class="card flex flex-col gap-5 p-5 text-sm">
            @if (! $attribute->isSupported())
                <p class="text-ink-muted">Akeneo attributes of type <code class="font-mono text-xs text-ink">{{ $attribute->type }}</code> cannot be sent to Shopify.</p>
            @else
                @php($locked = $attribute->hasDefinition())
                <form method="POST" action="{{ route('attributes.update', $attribute) }}" class="grid gap-5 sm:grid-cols-2">
                    @csrf
                    @method('PUT')
                    <label class="flex items-center gap-2 sm:col-span-2">
                        <input type="hidden" name="shopify_enabled" value="0">
                        <input type="checkbox" name="shopify_enabled" value="1" class="checkbox" @checked(old('shopify_enabled', $attribute->shopify_enabled))>
                        Send this attribute to Shopify
                    </label>
                    <label class="flex flex-col gap-2">
                        <span class="label-mono">Name in Shopify</span>
                        <input type="text" name="shopify_name" value="{{ old('shopify_name', $attribute->shopifyName()) }}" @disabled($locked) class="field">
                    </label>
                    <label class="flex flex-col gap-2">
                        <span class="label-mono">Metafield</span>
                        <span class="flex items-center gap-2">
                            <code class="font-mono text-ink-subtle">{{ \App\Models\AkeneoAttribute::SHOPIFY_NAMESPACE }}.</code>
                            <input type="text" name="shopify_key" value="{{ old('shopify_key', $attribute->shopifyKey()) }}" @disabled($locked) class="field w-full font-mono">
                        </span>
                    </label>
                    <label class="flex flex-col gap-2">
                        <span class="label-mono">Type</span>
                        <select name="shopify_type" @disabled($locked) class="field font-mono">
                            @foreach ($attribute->compatibleShopifyTypes() as $type)
                                <option value="{{ $type }}" @selected(old('shopify_type', $attribute->shopifyType()) === $type)>{{ $type }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="flex items-end">
                        <button type="submit" class="btn-secondary">Save</button>
                    </div>
                    @if ($locked)
                        <p class="text-xs text-ink-subtle sm:col-span-2">The definition exists in Shopify, so its name, key and type can no longer change here. Delete it in Shopify to change them.</p>
                    @endif
                </form>

                <div class="flex flex-wrap items-center gap-3 border-t border-line pt-5">
                    @if ($locked)
                        <span class="label-mono">Definition</span>
                        <code class="font-mono text-xs text-brand">{{ $attribute->shopify_definition_id }}</code>
                    @else
                        <form method="POST" action="{{ route('attributes.definition.store', $attribute) }}">
                            @csrf
                            <button type="submit" @disabled(! $attribute->shopify_enabled) class="btn-primary">Create in Shopify</button>
                        </form>
                        @unless ($attribute->shopify_enabled)
                            <span class="text-ink-subtle">Enable and save the attribute first.</span>
                        @endunless
                    @endif
                    @if ($attribute->shopify_definition_checked_at)
                        <span class="ml-auto text-xs text-ink-subtle">Last checked {{ $attribute->shopify_definition_checked_at->diffForHumans() }}</span>
                    @endif
                </div>

                @if ($attribute->shopify_definition_error)
                    <p class="alert-error">{{ $attribute->shopify_definition_error }}</p>
                @endif
            @endif
        </div>
    </section>
</x-layouts.app>
