<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CallLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SuperAdminAnalyticsController extends Controller
{
    /**
     * Platform-wide operational overview. This deliberately exposes counts and
     * aggregates only; individual call details stay behind organization policies.
     */
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $from = now()->subDays(29)->startOfDay();
        $to = now()->endOfDay();
        $dialect = DB::getDriverName();
        $dateExpression = $dialect === 'sqlite'
            ? "date(started_at)"
            : "DATE(started_at)";

        $daily = CallLog::query()
            ->whereBetween('started_at', [$from, $to])
            ->selectRaw("{$dateExpression} as date, count(*) as calls, coalesce(sum(duration), 0) as seconds")
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $activity = collect(range(0, 29))->map(function (int $offset) use ($daily): array {
            $date = now()->subDays(29 - $offset)->toDateString();
            $entry = $daily->get($date);

            return [
                'date' => $date,
                'calls' => (int) ($entry->calls ?? 0),
                'seconds' => (int) ($entry->seconds ?? 0),
            ];
        });

        $organizationUsage = Organization::query()
            ->withCount(['memberships as users_count', 'extensions'])
            ->withSum(['callLogs as call_seconds' => fn ($query) => $query->whereBetween('started_at', [$from, $to])], 'duration')
            ->withCount(['callLogs as calls_count' => fn ($query) => $query->whereBetween('started_at', [$from, $to])])
            ->orderByDesc('calls_count')
            ->limit(10)
            ->get()
            ->map(fn (Organization $organization): array => [
                'id' => $organization->public_id,
                'name' => $organization->name,
                'status' => $organization->status?->value ?? $organization->status,
                'users_count' => (int) $organization->users_count,
                'extensions_count' => (int) $organization->extensions_count,
                'calls_count' => (int) $organization->calls_count,
                'call_seconds' => (int) ($organization->call_seconds ?? 0),
            ]);

        return response()->json([
            'data' => [
                'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'totals' => [
                    'users' => User::query()->count(),
                    'organizations' => Organization::query()->count(),
                    'calls_30d' => (int) $activity->sum('calls'),
                    'call_seconds_30d' => (int) $activity->sum('seconds'),
                ],
                'activity' => $activity->values(),
                'organizations' => $organizationUsage->values(),
            ],
        ]);
    }
}
