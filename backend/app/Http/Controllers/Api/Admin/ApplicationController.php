<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminPermission;
use App\Models\Application;
use App\Models\EquipmentUnit;
use App\Models\LeaseAgreement;
use App\Models\RiskProfile;
use App\Models\User;
use App\Notifications\ApplicationStatusChangedNotification;
use App\Notifications\NewApplicationSubmittedNotification;
use App\Services\LeaseEngine;
use App\Services\RiskScoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Milestone 2/3 — turns the admin New Application wizard into a real,
 * persisted Application + LeaseAgreement (+ EquipmentUnit, CustomerProfile,
 * RiskProfile). A LeaseAgreement is created alongside the Application at
 * submission time (it carries the agreed terms); the Payment schedule is
 * only generated once the application reaches "funded_paid" — see update().
 */
class ApplicationController extends Controller
{
    public function index()
    {
        $applications = Application::with([
            'customer:id,name,email,phone',
            'reviewedBy:id,name',
            'leaseAgreement.equipmentUnit',
        ])->latest()->get();

        return response()->json(['data' => $applications]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'registered_customer_id' => ['required', 'integer', 'exists:users,id'],
            'cell_phone' => ['nullable', 'string', 'max:30'],
            'mailing_address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:2'],
            'zip' => ['nullable', 'string', 'max:10'],
            'date_of_birth' => ['nullable', 'date'],
            'drivers_license' => ['nullable', 'string', 'max:60'],
            'id_document' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],

            'residence_type' => ['nullable', 'string', 'max:30'],
            'years_at_residence' => ['nullable', 'string', 'max:10'],
            'income_source' => ['nullable', 'string', 'max:30'],
            'gross_monthly_income' => ['nullable', 'numeric', 'min:0'],
            'move_notification_agreed' => ['nullable', 'boolean'],

            'sales_person' => ['nullable', 'string', 'max:255'],
            'condition' => ['nullable', 'in:new,used'],
            'make' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'serial' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'ldw' => ['nullable', 'in:yes,no'],
            'cash_price' => ['required', 'numeric', 'min:0'],
            'year' => ['nullable', 'string', 'max:10'],
            'promo_code' => ['nullable', 'string', 'max:60'],

            'term_months' => ['required', 'integer', 'min:1', 'max:120'],
            'monthly_rental' => ['required', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'security_deposit' => ['nullable', 'numeric', 'min:0'],
            'payment_due_day' => ['nullable', 'string', 'max:10'],
            'autopay' => ['nullable', 'in:yes,no'],
        ]);

        $customer = User::findOrFail($data['registered_customer_id']);
        abort_unless($customer->isCustomer(), 422, 'Selected account is not a customer.');

        // Equipment unit — reuse-by-serial where possible, disambiguate on collision
        // (multiple applications commonly arrive with a placeholder "NA" serial).
        $serial = trim($data['serial'] ?? '') ?: 'NA';
        if (EquipmentUnit::where('serial_number', $serial)->exists()) {
            $serial = $serial.'-'.Str::upper(Str::random(5));
        }

        $equipmentUnit = EquipmentUnit::create([
            'model' => trim(($data['make'] ?? '').' '.($data['model'] ?? '')) ?: 'Unspecified',
            'serial_number' => $serial,
            'condition_notes' => trim(sprintf(
                'Condition: %s · Year: %s%s',
                $data['condition'] ?? 'unspecified',
                $data['year'] ?? 'unspecified',
                ! empty($data['description']) ? " — {$data['description']}" : '',
            )),
            'status' => EquipmentUnit::STATUS_LEASED,
        ]);

        $application = Application::create([
            'customer_id' => $customer->id,
            'status' => Application::STATUS_SUBMITTED,
            'internal_notes' => ! empty($data['sales_person']) ? "Sales person: {$data['sales_person']}" : null,
        ]);

        $termMonths = (int) $data['term_months'];
        $monthlyRental = (float) $data['monthly_rental'];
        $taxRate = (float) ($data['tax_rate'] ?? 0) / 100;
        $startDate = now()->toDateString();

        $leaseAgreement = LeaseAgreement::create([
            'application_id' => $application->id,
            'customer_id' => $customer->id,
            'equipment_unit_id' => $equipmentUnit->id,
            'term_months' => $termMonths,
            'start_date' => $startDate,
            'renewal_date' => now()->addMonthNoOverflow()->toDateString(),
            'payment_due_day' => $data['payment_due_day'] ?? null,
            'autopay_enabled' => ($data['autopay'] ?? 'no') === 'yes',
            'monthly_rental_payment' => $monthlyRental,
            'sales_tax_rate' => $taxRate,
            'security_deposit' => $data['security_deposit'] ?? 0,
            'cash_price' => $data['cash_price'],
            'total_rental_purchase_price' => LeaseEngine::totalRentalPurchasePrice($monthlyRental, $termMonths),
            'rental_payments_paid_to_date' => 0,
            'additional_funds' => 0,
            'ownership_status' => LeaseAgreement::OWNERSHIP_LEASING,
            'ldw_selected' => ($data['ldw'] ?? 'no') === 'yes',
            'promo_code' => $data['promo_code'] ?? null,
        ]);

        $equipmentUnit->update([
            'expected_return_or_ownership_date' => now()->addMonthsNoOverflow($termMonths)->toDateString(),
        ]);

        $mappedResidenceType = RiskScoringService::mapResidenceType($data['residence_type'] ?? null);

        $idDocumentPath = $request->hasFile('id_document')
            ? $request->file('id_document')->store('id-documents', 'local')
            : null;

        $customer->customerProfile()->updateOrCreate(['user_id' => $customer->id], array_filter([
            'government_id_type' => ! empty($data['drivers_license']) ? 'drivers_license' : null,
            'government_id_number' => $data['drivers_license'] ?? null,
            'government_id_document_path' => $idDocumentPath,
            'address_line_1' => $data['mailing_address'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'zip' => $data['zip'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'residence_type' => $mappedResidenceType,
            'years_at_residence' => $data['years_at_residence'] ?? null,
            'move_notification_agreed' => $data['move_notification_agreed'] ?? false,
            'employment_status' => $data['income_source'] ?? null,
            'monthly_income' => $data['gross_monthly_income'] ?? null,
        ], fn ($value) => $value !== null));

        if (! empty($data['cell_phone'])) {
            $customer->update(['phone' => $data['cell_phone']]);
        }

        // Underwriting policy: apartment residences are declined immediately, before scoring.
        if (RiskScoringService::requiresAutoDecline($mappedResidenceType)) {
            $application->update([
                'status' => Application::STATUS_DECLINED,
                'status_notes' => 'Apartments are automatically declined per underwriting policy.',
            ]);
        }

        RiskScoringService::evaluate($customer, $monthlyRental);

        $recipients = User::where('role', User::ROLE_SUPER_ADMIN)
            ->orWhere(function ($query) {
                $query->where('role', User::ROLE_ADMIN)
                    ->where(function ($inner) {
                        $inner->whereDoesntHave('adminPermissions')
                            ->orWhereHas('adminPermissions', fn ($p) => $p->where('permission', AdminPermission::APPLICATION_REVIEW));
                    });
            })->get();
        Notification::send($recipients, new NewApplicationSubmittedNotification($application));

        return response()->json(['data' => $this->present($application->fresh())], 201);
    }

    public function show(Application $application)
    {
        return response()->json(['data' => $this->present($application)]);
    }

    public function update(Request $request, Application $application)
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(Application::ALL_STATUSES)],
            'status_notes' => ['sometimes', 'nullable', 'string'],
            'lease' => ['sometimes', 'array'],
            'lease.term_months' => ['sometimes', 'integer', 'min:1', 'max:120'],
            'lease.monthly_rental_payment' => ['sometimes', 'numeric', 'min:0'],
            'lease.sales_tax_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'lease.security_deposit' => ['sometimes', 'numeric', 'min:0'],
            'lease.autopay_enabled' => ['sometimes', 'boolean'],
            'lease.ldw_selected' => ['sometimes', 'boolean'],
            'lease.promo_code' => ['sometimes', 'nullable', 'string', 'max:60'],
            'equipment' => ['sometimes', 'array'],
            'equipment.model' => ['sometimes', 'string', 'max:255'],
            'equipment.serial_number' => ['sometimes', 'string', 'max:255'],
            'equipment.condition_notes' => ['sometimes', 'nullable', 'string'],
            'customer' => ['sometimes', 'array'],
            'customer.address_line_1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer.city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer.state' => ['sometimes', 'nullable', 'string', 'max:2'],
            'customer.zip' => ['sometimes', 'nullable', 'string', 'max:10'],
            'customer.residence_type' => ['sometimes', 'nullable', 'string', 'max:30'],
            'risk' => ['sometimes', 'array'],
            'risk.identity_verification_status' => ['sometimes', Rule::in(['pending', 'verified', 'failed'])],
            'risk.employment_verification_status' => ['sometimes', Rule::in(['pending', 'verified', 'failed'])],
            'risk.bank_verification_status' => ['sometimes', Rule::in(['pending', 'verified', 'failed'])],
            'risk.background_check_status' => ['sometimes', Rule::in(['pending', 'clear', 'flagged'])],
            'risk.background_check_notes' => ['sometimes', 'nullable', 'string'],
        ]);

        if (array_key_exists('status', $data)) {
            $application->update([
                'status' => $data['status'],
                'status_notes' => $data['status_notes'] ?? $application->status_notes,
                'reviewed_by' => Auth::id(),
            ]);

            // Terms are locked in once approved, so the payment schedule is
            // generated here — well before "funded_paid", which just means
            // the lease is live and funds have moved.
            if ($data['status'] === Application::STATUS_APPROVED) {
                $lease = $application->leaseAgreement;
                if ($lease && ! $lease->payments()->exists()) {
                    LeaseEngine::generatePaymentSchedule($lease);
                }
            }

            $application->customer->notify(new ApplicationStatusChangedNotification($application->fresh()));
        }

        if ($lease = $application->leaseAgreement) {
            if (! empty($data['lease'])) {
                $lease->update($data['lease']);
                if (isset($data['lease']['term_months']) || isset($data['lease']['monthly_rental_payment'])) {
                    $lease->update([
                        'total_rental_purchase_price' => LeaseEngine::totalRentalPurchasePrice(
                            (float) $lease->monthly_rental_payment,
                            (int) $lease->term_months,
                        ),
                    ]);
                }
            }

            if (! empty($data['equipment']) && $lease->equipmentUnit) {
                $lease->equipmentUnit->update($data['equipment']);
            }
        }

        if (! empty($data['customer'])) {
            $application->customer->customerProfile()->updateOrCreate(
                ['user_id' => $application->customer_id],
                $data['customer'],
            );
        }

        if (! empty($data['risk'])) {
            RiskProfile::updateOrCreate(['customer_id' => $application->customer_id], $data['risk']);
        }

        return response()->json(['data' => $this->present($application->fresh())]);
    }

    public function destroy(Application $application)
    {
        $application->delete();

        return response()->json(null, 204);
    }

    /** Streams the applicant's uploaded ID document — never exposed via a public URL. */
    public function idDocument(Application $application)
    {
        $path = $application->customer->customerProfile?->government_id_document_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path);
    }

    /** Attaches the LeaseEngine's live EPO figures to the loaded lease agreement, if one exists. */
    private function present(Application $application): array
    {
        $application->load([
            'customer.customerProfile',
            'customer.riskProfile.redFlags',
            'reviewedBy:id,name',
            'leaseAgreement.equipmentUnit',
            'leaseAgreement.payments',
            'leaseAgreement.contract',
        ]);

        $payload = $application->toArray();

        if ($lease = $application->leaseAgreement) {
            $payload['lease_agreement']['sales_tax_amount'] = $lease->salesTaxAmount();
            $payload['lease_agreement']['total_monthly_payment'] = $lease->totalMonthlyPayment();
            $payload['lease_agreement']['payments_made'] = $lease->paymentsMadeCount();
            $payload['lease_agreement']['epo_today'] = LeaseEngine::epoToday($lease);
            $payload['lease_agreement']['epo_schedule'] = LeaseEngine::fullSchedule($lease);
        }

        return $payload;
    }
}
