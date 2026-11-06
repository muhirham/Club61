<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\PersonName;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => PersonName::clean($this->input('name'))]);
        }

        // Modul 33: nomor HP disimpan dalam format "08…" — sama dengan kasir POS & app, supaya dikenali di semua jalur.
        if ($this->has('phone')) {
            $raw = trim((string) $this->input('phone'));
            $this->merge(['phone' => $raw === '' ? null : (PhoneNumber::normalize($raw) ?? $raw)]);
        }
    }

    public function messages(): array
    {
        return [
            'phone.required' => __('account.profile.phone_required'),
            'phone.regex' => __('account.register.phone_invalid'),
            'phone.unique' => __('account.profile.phone_taken'),
        ];
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => PersonName::rules(),
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            // Belum punya nomor → boleh dikosongkan; sudah punya → tidak boleh dihapus (identitas di kasir & login).
            'phone' => [
                'sometimes',
                filled($this->user()->phone) ? 'required' : 'nullable',
                'string',
                'regex:/^0\d{7,19}$/',
                Rule::unique(User::class, 'phone')->ignore($this->user()->id)->whereNull('deleted_at'),
            ],
        ];
    }
}
