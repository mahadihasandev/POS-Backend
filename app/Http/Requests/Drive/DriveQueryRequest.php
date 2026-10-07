<?php

declare(strict_types=1);

namespace App\Http\Requests\Drive;

use Illuminate\Foundation\Http\FormRequest;

class DriveQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'parent_id' => ['nullable', 'integer'],
            'type' => ['nullable', 'string', 'in:file,folder'],
            'q' => ['nullable', 'string', 'max:100'],
            'starred' => ['nullable', 'boolean'],
            'trashed' => ['nullable', 'boolean'],
            'sort_by' => ['nullable', 'string', 'in:name,created_at,updated_at,size_bytes,type'],
            'sort_order' => ['nullable', 'string', 'in:asc,desc,ASC,DESC'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
