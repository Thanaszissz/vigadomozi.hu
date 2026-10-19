<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

class CustomerAdminController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        return response()->json(
            Customer::query()
                ->when($search !== '', function ($q) use ($search) {
                    $like = '%' . addcslashes($search, '%_\\') . '%';
                    $q->where(fn($w) => $w->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('phone', 'like', $like));
                })
                ->orderBy('name')
                ->get()
        );
    }

    public function show(Customer $customer)
    {
        return response()->json($customer);
    }

    public function store(Request $request)
    {
        $customer = Customer::create($this->validated($request));

        ActivityLogger::log(
            'customer.created',
            sprintf('Vásárló felvéve: %s', $customer->name),
            $customer,
            subjectLabel: $customer->name
        );

        return response()->json($customer, 201);
    }

    public function update(Request $request, Customer $customer)
    {
        $customer->update($this->validated($request));

        ActivityLogger::log(
            'customer.updated',
            sprintf('Vásárló módosítva: %s', $customer->name),
            $customer,
            subjectLabel: $customer->name
        );

        return response()->json($customer->fresh());
    }

    public function destroy(Customer $customer)
    {
        $label = $customer->name;
        $customer->delete();

        ActivityLogger::log('customer.deleted', 'Vásárló törölve: ' . $label, null, subjectLabel: $label);

        return response()->json(['message' => 'Vásárló törölve.']);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50', 'regex:/^[0-9+\-\s()\/.]+$/'],
            'zip' => ['nullable', 'string', 'max:10', 'regex:/^[0-9A-Za-z\- ]+$/'],
        ], [
            'phone.regex' => 'A telefonszám csak számjegyeket és a + - ( ) / . jeleket tartalmazhatja.',
            'zip.regex' => 'Az irányítószám csak betűket, számjegyeket és kötőjelet tartalmazhat.',
        ]);

        return [
            'name' => trim($data['name']),
            'email' => filled($data['email'] ?? null) ? trim($data['email']) : null,
            'phone' => filled($data['phone'] ?? null) ? trim($data['phone']) : null,
            'zip' => filled($data['zip'] ?? null) ? trim($data['zip']) : null,
        ];
    }
}
