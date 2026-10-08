<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Models\Customer;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ContactImportController extends BaseApiController
{
    public function store(Request $request): JsonResponse
    {
        return $this->import($request, Customer::class, 'area');
    }

    public function suppliers(Request $request): JsonResponse
    {
        return $this->import($request, Supplier::class, 'address');
    }

    private function import(Request $request, string $model, string $location): JsonResponse
    {
        $request->validate(['file' => 'required|file|max:2048|extensions:csv']);
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        try {
            $headers = fgetcsv($handle, 0, ',', '"', '');
            if (! $headers) {
                throw ValidationException::withMessages(['file' => 'CSV is empty.']);
            }
            $headers = array_map(fn ($value) => strtolower(trim((string) $value, "\xEF\xBB\xBF \t\r\n")), $headers);
            if (count($headers) !== count(array_unique($headers)) || array_diff(['name', 'code', 'phone', $location], $headers) || array_diff($headers, ['name', 'code', 'phone', $location])) {
                throw ValidationException::withMessages(['file' => 'Use the template headers: name,code,phone,'.$location.'.']);
            }
            $rows = [];
            $line = 1;
            while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $line++;
                if (count($cells) === 1 && trim((string) $cells[0]) === '') {
                    continue;
                }
                if (count($cells) !== count($headers)) {
                    throw ValidationException::withMessages(['file' => "CSV line {$line} has the wrong number of columns."]);
                }
                if (count($rows) >= 1000) {
                    throw ValidationException::withMessages(['file' => 'Import at most 1,000 contacts per file.']);
                }
                $row = array_combine($headers, array_map(fn ($value) => trim((string) $value), $cells));
                $validator = Validator::make($row, ['name' => 'required|string|max:255', 'code' => 'required|string|max:100', 'phone' => 'nullable|string|max:30', $location => 'nullable|string|max:255']);
                if ($validator->fails()) {
                    throw ValidationException::withMessages(['file' => "CSV line {$line}: ".$validator->errors()->first()]);
                }
                $rows[] = $row;
            }
            if (! $rows) {
                throw ValidationException::withMessages(['file' => 'CSV must contain at least one contact.']);
            }
            $codes = array_column($rows, 'code');
            if (count($codes) !== count(array_unique($codes)) || $model::whereIn('code', $codes)->exists()) {
                throw ValidationException::withMessages(['file' => 'Contact codes must be unique, including existing records. No rows were imported.']);
            }
            DB::transaction(function () use ($rows, $model): void {
                foreach ($rows as $row) {
                    $model::create($row + ($model === Customer::class ? ['previous_due' => 0, 'advanced_amount' => 0] : []));
                }
            });

            return $this->successResponse(['imported' => count($rows)], 'Contacts imported.', 201);
        } finally {
            fclose($handle);
        }
    }
}
