<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNoticiaRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title'   => ['required', 'string', 'max:150'],
            'excerpt' => ['nullable', 'string', 'max:300'],
            'body'    => ['required', 'string', 'max:10000'],
            'status'  => ['required', Rule::in(['borrador', 'publicada'])],
        ];
    }
}