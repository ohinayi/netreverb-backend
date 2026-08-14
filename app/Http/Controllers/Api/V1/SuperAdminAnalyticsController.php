<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CallLog;
use App\Models\Organization;
use App\Models\SipProvisioningState;
use App\Models\User;
use App\Services\Messaging\OutboundMessagingReadiness;
use App\Services\Payments\PaymentGatewayService;
use App\Services\SystemMonitoring\SystemResourceSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SuperAdminAnalyticsController extends Controller
{
    public function __construct(
        private readonly OutboundMessagingReadiness $messagingReadiness,
        private readonly PaymentGatewayService $payments,
        private readonly SystemResourceSnapshot $systemResources,
    ) {}

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
            ? 'date(started_at)'
            : 'DATE(started_at)';

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

        $organizationUsagePage = Organization::query()
            ->withCount(['memberships as users_count', 'extensions'])
            ->withSum(['callLogs as call_seconds' => fn ($query) => $query->whereBetween('started_at', [$from, $to])], 'duration')
            ->withCount(['callLogs as calls_count' => fn ($query) => $query->whereBetween('started_at', [$from, $to])])
            ->withSum(['callLogs as recording_bytes' => fn ($query) => $query->where('recording_status', 'completed')], 'recording_size')
            ->orderByDesc('calls_count')
            ->paginate(10, ['*'], 'organizations_page');

        $organizationUsage = $organizationUsagePage->getCollection()
            ->map(fn (Organization $organization): array => [
                'id' => $organization->public_id,
                'name' => $organization->name,
                'status' => $organization->status?->value ?? $organization->status,
                'users_count' => (int) $organization->users_count,
                'extensions_count' => (int) $organization->extensions_count,
                'calls_count' => (int) $organization->calls_count,
                'call_seconds' => (int) ($organization->call_seconds ?? 0),
                'recording_bytes' => (int) ($organization->recording_bytes ?? 0),
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
                'organizations_meta' => [
                    'current_page' => $organizationUsagePage->currentPage(),
                    'last_page' => $organizationUsagePage->lastPage(),
                    'total' => $organizationUsagePage->total(),
                ],
                'health' => [
                    'queue' => [
                        'pending_jobs' => (int) DB::table('jobs')->count(),
                        'failed_jobs' => (int) DB::table('failed_jobs')->count(),
                    ],
                    'provisioning' => [
                        'failed' => SipProvisioningState::query()->where('status', 'failed')->count(),
                        'stale_pending' => SipProvisioningState::query()
                            ->where('status', 'pending')
                            ->where('updated_at', '<', now()->subMinutes(15))
                            ->count(),
                    ],
                    'recordings' => [
                        'failed' => CallLog::query()->where('recording_status', 'failed')->count(),
                        'orphaned' => CallLog::query()->where('recording_status', 'orphaned')->count(),
                    ],
                    'outbound_messaging' => $this->messagingReadiness->status(),
                    'payments' => [
                        'enabled' => $this->payments->enabled(),
                        'gateways' => $this->payments->readiness(),
                    ],
                ],
                'system' => $this->systemResources->capture(),
            ],
        ]);
    }
}
