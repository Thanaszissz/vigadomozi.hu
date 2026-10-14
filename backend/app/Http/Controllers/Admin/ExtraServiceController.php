<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExtraService;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Egyéb szolgáltatások (Sárkányvár, Néprajzi gyűjtemények, élménykártya stb.) árainak kezelése. */
class ExtraServiceController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(ExtraService::orderBy('sort_order')->orderBy('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $service = ExtraService::create($data + ['sort_order' => (int) ExtraService::max('sort_order') + 1]);

        ActivityLogger::log(
            'settings.service_changed',
            sprintf('Egyéb szolgáltatás felvéve: %s (%s Ft)', $service->name, number_format($service->price, 0, ',', ' ')),
            $service,
            ['price' => $service->price],
            subjectLabel: $service->name
        );

        return response()->json($service, 201);
    }

    public function update(Request $request, ExtraService $extraService): JsonResponse
    {
        $data = $this->validated($request, $extraService);
        $oldName = $extraService->name;
        $oldPrice = $extraService->price;

        $extraService->update($data);

        if ($oldName !== $extraService->name || $oldPrice !== $extraService->price) {
            ActivityLogger::log(
                'settings.service_changed',
                sprintf(
                    'Egyéb szolgáltatás módosítva: %s%s (%s → %s Ft)',
                    $extraService->name,
                    $oldName !== $extraService->name ? " (korábban: {$oldName})" : '',
                    number_format($oldPrice, 0, ',', ' '),
                    number_format($extraService->price, 0, ',', ' ')
                ),
                $extraService,
                ['old_price' => $oldPrice, 'new_price' => $extraService->price, 'old_name' => $oldName],
                subjectLabel: $extraService->name
            );
        }

        return response()->json($extraService->fresh());
    }

    public function destroy(ExtraService $extraService): JsonResponse
    {
        $label = sprintf('%s (%s Ft)', $extraService->name, number_format($extraService->price, 0, ',', ' '));
        $extraService->delete();

        ActivityLogger::log('settings.service_changed', 'Egyéb szolgáltatás törölve: ' . $label, null, [], subjectLabel: $label);

        return response()->json(['message' => 'A szolgáltatás törölve.']);
    }

    private function validated(Request $request, ?ExtraService $current = null): array
    {
        $unique = 'unique:extra_services,name' . ($current ? ',' . $current->id : '');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'regex:/^[^<>\x00-\x1F\x7F]+$/u', $unique],
            'price' => ['required', 'integer', 'min:0', 'max:10000000'],
        ], [
            'name.unique' => 'Már van ilyen nevű szolgáltatás.',
            'price.integer' => 'Az ár egész szám lehet (forintban).',
            'price.min' => 'Az ár nem lehet negatív.',
        ]);

        return ['name' => trim($data['name']), 'price' => (int) $data['price']];
    }
}
