<?php

namespace App\Http\Requests;

use App\Models\AkeneoAttribute;
use App\Services\ShopifyAdminService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAkeneoAttributeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Name, key and type are only validated, and saved, for a supported attribute whose Shopify definition doesn't exist yet.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $attribute = $this->akeneoAttribute();

        if (! $this->canEditDefinition()) {
            return ['shopify_enabled' => ['boolean']];
        }

        return [
            'shopify_enabled' => ['boolean'],
            'shopify_name' => ['required', 'string', 'max:255'],
            'shopify_key' => [
                'required',
                'string',
                'regex:/^[A-Za-z0-9_-]{2,64}$/',
                Rule::notIn([ShopifyAdminService::AKENEO_CODE_KEY]),
                Rule::unique(AkeneoAttribute::class, 'shopify_key')->ignore($attribute),
            ],
            'shopify_type' => ['required', Rule::in($attribute->compatibleShopifyTypes())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'shopify_key.regex' => 'The key must be 2 to 64 letters, digits, underscores or hyphens.',
            'shopify_key.not_in' => 'akeneo.code is reserved for the product custom ID.',
            'shopify_key.unique' => 'Another attribute already uses this key.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->boolean('shopify_enabled') && ! $this->akeneoAttribute()->isSupported()) {
                    $validator->errors()->add('shopify_enabled', "Akeneo attributes of type {$this->akeneoAttribute()->type} cannot be sent to Shopify.");
                }

                $key = $this->input('shopify_key');

                if ($this->canEditDefinition() && is_string($key) && AkeneoAttribute::query()
                    ->whereKeyNot($this->akeneoAttribute()->getKey())
                    ->whereNull('shopify_key')
                    ->where('code', $key)
                    ->exists()) {
                    $validator->errors()->add('shopify_key', 'Another attribute already uses this key.');
                }
            },
        ];
    }

    public function akeneoAttribute(): AkeneoAttribute
    {
        return $this->route('attribute');
    }

    public function canEditDefinition(): bool
    {
        return $this->akeneoAttribute()->isSupported() && ! $this->akeneoAttribute()->hasDefinition();
    }
}
