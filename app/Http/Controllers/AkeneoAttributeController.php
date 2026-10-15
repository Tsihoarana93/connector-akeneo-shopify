<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAkeneoAttributeRequest;
use App\Models\AkeneoAttribute;
use App\Models\SyncRun;
use App\Services\AkeneoAttributeSync;
use App\Services\AttributeDefinitionManager;
use App\Services\AttributeUsage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class AkeneoAttributeController extends Controller
{
    public const FILTERS = ['enabled' => 'Enabled only', 'with_values' => 'With values only'];

    public function index(Request $request, AttributeUsage $usage): View
    {
        $search = trim((string) $request->query('search'));
        $filter = array_key_exists((string) $request->query('filter'), self::FILTERS) ? $request->query('filter') : null;
        $usageByCode = $usage->all();

        $pattern = '%'.addcslashes(mb_strtolower($search), '\\%_').'%';

        $attributes = AkeneoAttribute::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereRaw('LOWER(code) LIKE ?', [$pattern])
                ->orWhereRaw('LOWER(CAST(labels AS CHAR)) LIKE ?', [$pattern])))
            ->when($filter === 'enabled', fn ($query) => $query->where('shopify_enabled', true))
            ->orderBy('code')
            ->get()
            ->when($filter === 'with_values', fn ($attributes) => $attributes->filter(fn (AkeneoAttribute $attribute): bool => isset($usageByCode[$attribute->code])));

        return view('attributes.index', [
            'attributes' => $attributes,
            'usage' => $usageByCode,
            'search' => $search,
            'filter' => $filter,
            'missingDefinitions' => AkeneoAttribute::query()->where('shopify_enabled', true)->whereNull('shopify_definition_id')->count(),
            'lastSyncedAt' => AkeneoAttribute::lastSyncedAt(),
        ]);
    }

    /**
     * Retrieve the attribute list from Akeneo now. Attributes no longer in Akeneo are kept; a sync with --prune deletes them.
     * It holds the sync command's lock, with the same lifetime, so it never writes attributes while a sync (possibly pruning) runs.
     */
    public function sync(AkeneoAttributeSync $attributeSync): RedirectResponse
    {
        $lock = SyncRun::lockFor(SyncRun::COMMANDS['sync']);

        if (! $lock->get()) {
            return back()->withErrors(['sync' => 'A sync from Akeneo is running; it updates the attributes too. Try again when it finishes.']);
        }

        $syncedAt = now()->startOfSecond();

        try {
            $synced = $attributeSync->sync($syncedAt);
        } catch (RequestException|ConnectionException|RuntimeException $exception) {
            return back()->withErrors(['sync' => "Akeneo could not be reached: {$exception->getMessage()}"]);
        } finally {
            $lock->release();
        }

        $stale = AkeneoAttribute::query()
            ->where(fn ($query) => $query->whereNull('synced_at')->orWhere('synced_at', '<', $syncedAt))
            ->count();

        return back()->with('status', "{$synced} attributes retrieved from Akeneo.".($stale > 0 ? " {$stale} local attributes are no longer in Akeneo; run a sync with Prune to delete them." : ''));
    }

    public function show(AkeneoAttribute $attribute, AttributeUsage $usage): View
    {
        return view('attributes.show', [
            'attribute' => $attribute,
            'usage' => $usage->of($attribute->code),
        ]);
    }

    public function update(UpdateAkeneoAttributeRequest $request, AkeneoAttribute $attribute): RedirectResponse
    {
        $attribute->shopify_enabled = $request->boolean('shopify_enabled');

        if ($request->canEditDefinition()) {
            $attribute->fill($request->safe()->only(['shopify_name', 'shopify_key', 'shopify_type']));
            $attribute->shopify_definition_error = null;
        }

        $attribute->save();

        return redirect()->route('attributes.show', $attribute)->with('status', 'The Shopify mapping has been saved.');
    }

    /**
     * Create, or link, the Shopify metafield definition of one enabled attribute.
     */
    public function storeDefinition(AkeneoAttribute $attribute, AttributeDefinitionManager $definitions): RedirectResponse
    {
        if (! $attribute->shopify_enabled) {
            return back()->withErrors(['definition' => 'Enable the attribute before creating its Shopify definition.']);
        }

        try {
            $created = $definitions->sync($attribute);
        } catch (RequestException|ConnectionException|RuntimeException $exception) {
            return back()->withErrors(['definition' => "Shopify could not be reached: {$exception->getMessage()}"]);
        }

        return $created
            ? back()->with('status', 'The Shopify definition '.AkeneoAttribute::SHOPIFY_NAMESPACE.".{$attribute->shopifyKey()} is ready.")
            : back()->withErrors(['definition' => $attribute->shopify_definition_error]);
    }

    /**
     * Create, or link, the Shopify metafield definitions of every enabled attribute that doesn't have one yet.
     */
    public function storeMissingDefinitions(AttributeDefinitionManager $definitions): RedirectResponse
    {
        $attributes = AkeneoAttribute::query()->where('shopify_enabled', true)->whereNull('shopify_definition_id')->orderBy('code')->get();
        $created = 0;

        try {
            foreach ($attributes as $attribute) {
                $created += (int) $definitions->sync($attribute);
            }
        } catch (RequestException|ConnectionException|RuntimeException $exception) {
            return back()->withErrors(['definition' => "Shopify could not be reached: {$exception->getMessage()}"]);
        }

        $failed = $attributes->count() - $created;

        return $failed === 0
            ? back()->with('status', "{$created} Shopify definitions are ready.")
            : back()->with('status', "{$created} Shopify definitions are ready.")->withErrors(['definition' => "{$failed} definitions could not be created; see the errors below."]);
    }
}
