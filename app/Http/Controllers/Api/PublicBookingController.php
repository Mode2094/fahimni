<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\PaidService;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\BookingService;
use App\Services\NotificationService;
use App\Services\PayPalGateway;
use App\Services\PayTabsGateway;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

class PublicBookingController extends Controller
{
    private function tenantBySlug(string $slug): Tenant
    {
        $tenant = Tenant::where('slug', $slug)->first();

        abort_if(! $tenant, 404, 'Institution not found.');

        return $tenant;
    }

    private function teachersOf(Tenant $tenant)
    {
        return TenantUser::where('tenant_id', $tenant->id)
            ->whereIn('role', ['teacher', 'owner', 'admin'])
            ->where('status', 'active')
            ->with('user');
    }

    public function teachers(string $slug): JsonResponse
    {
        $tenant = $this->tenantBySlug($slug);

        $teachers = $this->teachersOf($tenant)->get();

        $serviceCounts = PaidService::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->whereIn('user_id', $teachers->pluck('user_id'))
            ->groupBy('user_id')
            ->selectRaw('user_id, count(*) as services_count')
            ->get()
            ->pluck('services_count', 'user_id');

        $teachers = $teachers->map(fn (TenantUser $pivot) => [
            'id' => $pivot->user->id,
            'name' => $pivot->user->name,
            'title' => $pivot->user->metadata['title'] ?? null,
            'avatar_url' => $pivot->user->avatar_url,
            'role' => $pivot->role,
            'services_count' => (int) ($serviceCounts[$pivot->user_id] ?? 0),
        ]);

        return response()->json(['teachers' => $teachers]);
    }

    public function services(string $slug, Request $request): JsonResponse
    {
        $tenant = $this->tenantBySlug($slug);

        $query = PaidService::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->with('teacher');

        if ($request->input('teacher_id')) {
            $query->where('user_id', $request->input('teacher_id'));
        }

        if ($request->input('type') && in_array($request->input('type'), PaidService::TYPES, true)) {
            $query->where('type', $request->input('type'));
        }

        $services = $query->orderBy('created_at')->get()->map(fn (PaidService $s) => [
            'id' => $s->id,
            'type' => $s->type,
            'title' => $s->title,
            'description' => $s->description,
            'price' => $s->price,
            'currency' => $s->currency,
            'duration_minutes' => $s->duration_minutes,
            'teacher' => [
                'id' => $s->teacher->id,
                'name' => $s->teacher->name,
                'avatar_url' => $s->teacher->avatar_url,
            ],
        ]);

        return response()->json(['services' => $services]);
    }

    public function slots(string $slug, Request $request, BookingService $bookingService): JsonResponse
    {
        $tenant = $this->tenantBySlug($slug);

        $validated = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'teacher_id' => 'required|string|exists:users,id',
            'service_id' => 'nullable|string|exists:paid_services,id',
        ]);

        $teacher = $this->teachersOf($tenant)->where('user_id', $validated['teacher_id'])->first();
        abort_if(! $teacher, 404, 'Teacher not found in this institution.');

        $duration = BookingService::DEFAULT_SLOT_MINUTES;
        if ($validated['service_id'] ?? null) {
            $service = PaidService::where('tenant_id', $tenant->id)
                ->where('id', $validated['service_id'])
                ->where('is_active', true)
                ->first();
            abort_if(! $service, 404, 'Service not found.');
            $duration = $service->duration_minutes;
        }

        $slots = $bookingService->freeSlots($tenant, $teacher->user, $validated['date'], $duration);

        return response()->json(['slots' => $slots]);
    }

    public function store(string $slug, Request $request, BookingService $bookingService): JsonResponse
    {
        $tenant = $this->tenantBySlug($slug);

        $validated = $request->validate([
            'teacher_id' => 'required|string|exists:users,id',
            'starts_at' => 'required|date',
            'service_id' => 'nullable|string|exists:paid_services,id',
            'notes' => 'nullable|string|max:1000',
            'gateway' => 'required_with:service_id|nullable|in:paytabs,paypal',
        ]);

        $teacher = $this->teachersOf($tenant)->where('user_id', $validated['teacher_id'])->first();
        abort_if(! $teacher, 404, 'Teacher not found in this institution.');

        $student = $request->user();

        $service = null;
        $kind = Booking::KIND_APPOINTMENT;
        $price = 0;
        $duration = BookingService::DEFAULT_SLOT_MINUTES;

        if ($validated['service_id'] ?? null) {
            $service = PaidService::where('tenant_id', $tenant->id)
                ->where('id', $validated['service_id'])
                ->where('is_active', true)
                ->first();
            abort_if(! $service, 404, 'Service not found.');
            abort_if($service->user_id !== $teacher->user_id, 422, 'Service does not belong to the selected teacher.');

            $kind = Booking::KIND_SERVICE;
            $price = $service->price;
            $duration = $service->duration_minutes;
        }

        $tz = $bookingService->tenantTimezone($tenant);
        $startsAt = Carbon::parse($validated['starts_at'])->setTimezone($tz);
        $endsAt = $startsAt->copy()->addMinutes($duration);

        $isFreeAppointment = $kind === Booking::KIND_APPOINTMENT;

        abort_if(! $startsAt->isFuture(), 422, 'Booking must be in the future.');

        // The exact requested slot must be in the teacher's free slots for this date.
        $freeSlots = $bookingService->freeSlots($tenant, $teacher->user, $startsAt->format('Y-m-d'), $duration);
        $requestedUtc = $startsAt->copy()->setTimezone('UTC')->getTimestamp();
        $freeUtc = collect($freeSlots)->pluck('starts_at')->map(fn ($iso) => Carbon::parse($iso)->setTimezone('UTC')->getTimestamp());
        abort_if(! $freeUtc->contains($requestedUtc), 422, 'The selected time is not available.');

        // Eloquent stores datetimes as UTC wall time — normalize before persisting.
        $startsAt = $startsAt->setTimezone('UTC');
        $endsAt = $endsAt->setTimezone('UTC');

        DB::beginTransaction();
        try {
            $booking = Booking::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $tenant->id,
                'teacher_id' => $teacher->user_id,
                'student_id' => $student->id,
                'service_id' => $service?->id,
                'kind' => $kind,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => Booking::STATUS_PENDING,
                'price' => $price,
                'currency' => $service?->currency ?? 'JOD',
                'notes' => $validated['notes'] ?? null,
            ]);

            // Paid services require gateway payment before the booking is active.
            if (! $isFreeAppointment && (float) $price > 0) {
                $payment = $this->initiatePayment($request, $tenant, $booking, $validated['gateway']);

                DB::commit();

                return response()->json([
                    'booking' => $this->payload($booking),
                    'payment' => [
                        'id' => $payment->id,
                        'status' => $payment->status,
                        'redirect_url' => $payment->gateway_data['redirect_url'] ?? null,
                    ],
                    'requires_payment' => true,
                ], 201);
            }

            DB::commit();

            $this->notifyTeacher($tenant, $booking);

            return response()->json([
                'booking' => $this->payload($booking),
                'requires_payment' => false,
            ], 201);
        } catch (QueryException $e) {
            DB::rollBack();

            // Unique (teacher_id, starts_at) violation → the slot was taken
            // by a concurrent student between the availability check and the
            // insert. Report it as a friendly conflict, not a 500.
            if ($e->getCode() === '23505' || str_contains($e->getMessage(), 'uniq_bookings_teacher_starts_at')) {
                return response()->json(['message' => 'This time slot was just booked by someone else. Please pick another.'], 409);
            }

            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function myBookings(Request $request): JsonResponse
    {
        $bookings = Booking::where('student_id', $request->user()->id)
            ->with(['tenant', 'service', 'teacher', 'payment'])
            ->orderByDesc('starts_at')
            ->limit(100)
            ->get()
            ->map(fn (Booking $b) => $this->payload($b));

        return response()->json(['bookings' => $bookings]);
    }

    public function cancel(Request $request, Booking $booking): JsonResponse
    {
        abort_if($booking->student_id !== $request->user()->id, 403, 'Unauthorized.');

        abort_if(
            ! in_array($booking->status, [Booking::STATUS_PENDING, Booking::STATUS_CONFIRMED], true),
            422,
            'This booking cannot be cancelled.'
        );

        $booking->update(['status' => Booking::STATUS_CANCELLED]);

        return response()->json(['message' => 'Booking cancelled.', 'booking' => $this->payload($booking->fresh(['service']))]);
    }

    private function initiatePayment(Request $request, Tenant $tenant, Booking $booking, string $gateway): Payment
    {
        $user = $request->user();
        $currency = $booking->currency ?: 'JOD';

        // PayPal only supports a fixed set of currencies: convert the
        // platform currency (JOD) to USD using the admin-configured rate.
        $gatewayCurrency = $gateway === 'paytabs' ? $currency : 'USD';
        $amount = $booking->price;

        if ($gateway === 'paypal' && $currency !== 'USD') {
            $rate = (float) PlatformSetting::get('currency_rate', 1);
            $rate = $rate > 0 ? $rate : 1;
            $amount = round($booking->price * $rate, 2);
        }

        // Config-driven URLs only — the request Host header is attacker
        // controlled and would redirect payers to a phishing domain.
        $apiBase = rtrim(config('app.url'), '/');
        $frontend = rtrim(config('app.frontend_url') ?: config('app.url'), '/');

        $payment = Payment::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'transaction_id' => 'pending-'.str()->uuid(),
            'gateway' => $gateway,
            'amount' => $amount,
            'currency' => $gatewayCurrency,
            'status' => 'pending',
            'payment_method' => $gateway,
            'gateway_data' => [],
        ]);

        $customer = ['name' => $user->name, 'email' => $user->email, 'phone' => $user->phone ?? '0500000000'];
        $description = 'Booking - '.($booking->service?->title ?? 'Appointment');

        if ($gateway === 'paytabs') {
            abort_unless(PayTabsGateway::enabled() && PayTabsGateway::configured(), 422, 'PayTabs gateway is not configured.');

            $result = PayTabsGateway::createPayment([
                'cart_id' => $payment->id,
                'amount' => $booking->price,
                'currency' => $gatewayCurrency,
                'description' => $description,
                'customer' => $customer,
                'callback_url' => $apiBase.'/api/v1/payments/paytabs-callback',
                'return_url' => $frontend.'/book/success?gateway=paytabs&status=paid',
            ]);

            $payment->update(['gateway_data' => array_merge($payment->gateway_data ?? [], $result)]);

            return $payment;
        }

        abort_unless(PayPalGateway::enabled() && PayPalGateway::configured(), 422, 'PayPal gateway is not configured.');

        $result = PayPalGateway::createOrder([
            'cart_id' => $payment->id,
            'amount' => $amount,
            'currency' => $gatewayCurrency,
            'description' => $description,
            'brand_name' => 'Tadreesly',
            'return_url' => $apiBase.'/api/v1/payments/paypal-capture?payment_id='.$payment->id,
            'cancel_url' => $frontend.'/book/success?gateway=paypal&status=cancelled',
        ]);

        $payment->update([
            'gateway_data' => array_merge($payment->gateway_data ?? [], ['order_id' => $result['id'], 'redirect_url' => $result['approval_url']]),
        ]);

        return $payment;
    }

    private function notifyTeacher(Tenant $tenant, Booking $booking): void
    {
        $teacher = User::find($booking->teacher_id);
        if (! $teacher) {
            return;
        }

        app(NotificationService::class)->notify(
            $teacher,
            'booking.new',
            'حجز جديد',
            'لديك حجز جديد من طالب'.($booking->service ? ' ('.$booking->service->title.')' : ''),
            User::find($booking->student_id),
            $tenant->id,
            ['booking_id' => $booking->id],
        );
    }

    private function payload(Booking $b): array
    {
        return [
            'id' => $b->id,
            'kind' => $b->kind,
            'status' => $b->status,
            'starts_at' => $b->starts_at?->toIso8601String(),
            'ends_at' => $b->ends_at?->toIso8601String(),
            'price' => $b->price,
            'currency' => $b->currency,
            'notes' => $b->notes,
            'meeting_url' => $b->meeting_url,
            'tenant' => $b->tenant ? ['id' => $b->tenant->id, 'name' => $b->tenant->name, 'slug' => $b->tenant->slug] : null,
            'teacher' => $b->teacher ? ['id' => $b->teacher->id, 'name' => $b->teacher->name] : null,
            'service' => $b->service ? [
                'id' => $b->service->id,
                'type' => $b->service->type,
                'title' => $b->service->title,
                'price' => $b->service->price,
                'duration_minutes' => $b->service->duration_minutes,
            ] : null,
            'payment' => $b->payment ? ['id' => $b->payment->id, 'status' => $b->payment->status, 'gateway' => $b->payment->gateway] : null,
            'created_at' => $b->created_at?->toIso8601String(),
        ];
    }
}
