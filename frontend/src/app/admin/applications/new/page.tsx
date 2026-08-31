"use client";

import { Suspense, useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { PageHeroHeader } from "@/components/layout/PageHeroHeader";
import { WizardSteps, STEPS, type StepKey } from "@/components/applications/wizard/WizardSteps";
import { EquipmentStep } from "@/components/applications/wizard/EquipmentStep";
import { LeaseDetailsStep } from "@/components/applications/wizard/LeaseDetailsStep";
import { CustomerInfoStep } from "@/components/applications/wizard/CustomerInfoStep";
import { RiskVerificationStep } from "@/components/applications/wizard/RiskVerificationStep";
import { INITIAL_WIZARD_STATE, type WizardState } from "@/components/applications/wizard/types";
import { listCustomers } from "@/lib/customers";
import { createApplication } from "@/lib/applications";
import { ApiError } from "@/lib/api";
import type { AuthUser } from "@/types/auth";

export default function NewLeaseApplicationPage() {
  return (
    <Suspense fallback={null}>
      <NewLeaseApplicationForm />
    </Suspense>
  );
}

function NewLeaseApplicationForm() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const zipFromDashboard = searchParams.get("zip") ?? "";
  const [step, setStep] = useState<StepKey>("equipment");
  const [state, setState] = useState<WizardState>(() => ({
    ...INITIAL_WIZARD_STATE,
    zip: zipFromDashboard,
  }));
  const [customers, setCustomers] = useState<AuthUser[]>([]);
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);

  useEffect(() => {
    listCustomers()
      .then(setCustomers)
      .catch(() => setCustomers([]));
  }, []);

  function set<K extends keyof WizardState>(key: K, value: WizardState[K]) {
    setState((s) => ({ ...s, [key]: value }));
  }

  const index = STEPS.findIndex((s) => s.key === step);
  const isFirst = index === 0;
  const isLast = index === STEPS.length - 1;

  function goNext() {
    if (!isLast) setStep(STEPS[index + 1].key);
  }
  function goBack() {
    if (!isFirst) setStep(STEPS[index - 1].key);
  }

  async function submit() {
    setSubmitError(null);
    if (!state.registeredCustomerId) {
      setSubmitError("Select a registered customer before submitting.");
      setStep("customer");
      return;
    }
    setSubmitting(true);
    try {
      const application = await createApplication(state);
      router.push(`/admin/applications/${application.id}`);
    } catch (err) {
      setSubmitError(err instanceof ApiError ? err.message : "Could not submit the application. Please try again.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="space-y-6">
      <PageHeroHeader title="New lease application" subtitle="Complete the application below. Status updates go to the dealer.">
        <WizardSteps active={step} onSelect={setStep} />
      </PageHeroHeader>

      {step === "equipment" && <EquipmentStep state={state} set={set} />}
      {step === "lease" && <LeaseDetailsStep state={state} set={set} />}
      {step === "customer" && <CustomerInfoStep state={state} set={set} customers={customers} />}
      {step === "risk" && <RiskVerificationStep state={state} set={set} />}

      <div className="flex items-center justify-between">
        {isFirst ? (
          <button
            onClick={() => router.push("/admin/applications")}
            className="font-heading rounded-md border border-red-600 px-6 py-2.5 text-sm font-bold text-red-600 hover:bg-red-50"
          >
            ← Cancel
          </button>
        ) : (
          <button
            onClick={goBack}
            className="font-heading rounded-md border border-red-600 px-6 py-2.5 text-sm font-bold text-red-600 hover:bg-red-50"
          >
            ← Back
          </button>
        )}

        {isLast ? (
          <div className="flex flex-col items-end gap-2">
            {submitError && <p className="text-xs font-semibold text-red-600">{submitError}</p>}
            <button
              onClick={submit}
              disabled={submitting}
              className="font-heading rounded-md bg-red-600 px-6 py-2.5 text-sm font-bold text-white hover:bg-red-700 disabled:opacity-60"
            >
              {submitting ? "Submitting…" : "Submit Application →"}
            </button>
          </div>
        ) : (
          <button
            onClick={goNext}
            className="font-heading rounded-md bg-red-600 px-6 py-2.5 text-sm font-bold text-white hover:bg-red-700"
          >
            Next →
          </button>
        )}
      </div>
    </div>
  );
}
