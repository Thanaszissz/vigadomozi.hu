<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\ActivityLogger;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ActivityLogController extends Controller
{
    // A böngészőből bejelentett események (a nyomtatás csak a kliensen látszik)
    private const CLIENT_EVENTS = ['ticket.print', 'report.daily_close_print'];

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'user_id' => ['nullable', 'string', 'max:20'],
            'category' => ['nullable', Rule::in(array_keys(ActivityLogger::CATEGORIES))],
            'action' => ['nullable', Rule::in(array_keys(ActivityLogger::ACTIONS))],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $query = ActivityLog::query();

        if ($from = $request->query('from')) {
            $query->where('occurred_at', '>=', Carbon::createFromFormat('Y-m-d', $from, 'Europe/Budapest')->startOfDay()->utc());
        }
        if ($to = $request->query('to')) {
            $query->where('occurred_at', '<=', Carbon::createFromFormat('Y-m-d', $to, 'Europe/Budapest')->endOfDay()->utc());
        }

        $userId = $request->query('user_id');
        if ($userId === 'none') {
            $query->whereNull('user_id');
        } elseif ($userId !== null && $userId !== '') {
            $query->where('user_id', (int) $userId);
        }

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }
        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }
        if ($q = trim((string) $request->query('q'))) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $query->where(function ($w) use ($like) {
                $w->where('description', 'like', $like)
                    ->orWhere('user_name', 'like', $like)
                    ->orWhere('user_email', 'like', $like)
                    ->orWhere('subject_label', 'like', $like);
            });
        }

        $total = (clone $query)->count();
        $page = $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate(50);

        return response()->json([
            'total' => $total,
            'logs' => $page,
        ]);
    }

    /**
     * A szűrők lehetséges értékei.
     */
    public function meta(): JsonResponse
    {
        $users = ActivityLog::query()
            ->whereNotNull('user_id')
            ->selectRaw('user_id, max(user_name) as user_name')
            ->groupBy('user_id')
            ->orderBy('user_name')
            ->get()
            ->map(fn($u) => ['id' => (int) $u->user_id, 'name' => $u->user_name]);

        $actions = [];
        foreach (ActivityLogger::ACTIONS as $key => [$category, $label]) {
            $actions[] = ['key' => $key, 'category' => $category, 'label' => $label];
        }

        $categories = [];
        foreach (ActivityLogger::CATEGORIES as $key => $label) {
            $categories[] = ['key' => $key, 'label' => $label];
        }

        return response()->json([
            'categories' => $categories,
            'actions' => $actions,
            'users' => $users,
        ]);
    }

    public function clientEvent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(self::CLIENT_EVENTS)],
            'description' => ['required', 'string', 'max:400'],
            'details' => ['nullable', 'array'],
        ]);

        ActivityLogger::log($data['action'], $data['description'], null, $data['details'] ?? []);

        return response()->json(['status' => 'ok'], 201);
    }
}
