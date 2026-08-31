import { Field, FileInput, SelectInput, TextInput } from "@/components/applications/wizard/fields";
import type { WizardState } from "@/components/applications/wizard/types";
import { SectionHeading } from "@/components/dashboard/SectionHeading";
import type { AuthUser } from "@/types/auth";

export function CustomerInfoStep({
  state,
  set,
  customers,
  applyingAs,
}: {
  state: WizardState;
  set: <K extends keyof WizardState>(key: K, value: WizardState[K]) => void;
  customers: AuthUser[];
  /** Customer self-service mode: hides the "pick a customer" selector and shows this name/email instead. */
  applyingAs?: { name: string; email: string };
}) {
  return (
    <div className="rounded-xl border border-neutral-200 bg-white p-6">
      <SectionHeading title="Renter contact information" />
      <div className="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2">
        <div className="sm:col-span-2">
          {applyingAs ? (
            <Field label="Applying As">
              <div className="rounded-md border border-neutral-200 bg-neutral-50 px-3 py-2.5 text-sm text-neutral-700">
                {applyingAs.name} · {applyingAs.email}
              </div>
            </Field>
          ) : (
            <Field label="Registered Customer" required>
              <SelectInput
                value={state.registeredCustomerId}
                onChange={(v) => set("registeredCustomerId", v)}
                placeholder="Select a registered customer…"
                options={customers.map((c) => ({ value: String(c.id), label: `${c.name} · ${c.email}` }))}
              />
            </Field>
          )}
        </div>

        <Field label="Email" required>
          <TextInput value={state.email} onChange={(v) => set("email", v)} type="email" />
        </Field>
        <Field label="Cell Phone" required>
          <TextInput value={state.cellPhone} onChange={(v) => set("cellPhone", v)} placeholder="(000) 000-0000" />
        </Field>

        <div className="sm:col-span-2">
          <Field label="Mailing Address" required>
            <TextInput value={state.mailingAddress} onChange={(v) => set("mailingAddress", v)} placeholder="Street address" />
          </Field>
        </div>

        <Field label="City">
          <TextInput value={state.city} onChange={(v) => set("city", v)} />
        </Field>
        <Field label="State">
          <TextInput value={state.state} onChange={(v) => set("state", v)} />
        </Field>

        <Field label="Zip Code">
          <TextInput value={state.zip} onChange={(v) => set("zip", v)} />
        </Field>
        <Field label="Date of Birth" required>
          <TextInput value={state.dob} onChange={(v) => set("dob", v)} type="date" />
        </Field>

        <div className="sm:col-span-2">
          <Field label="Driver's License #" required>
            <TextInput value={state.driversLicense} onChange={(v) => set("driversLicense", v)} />
          </Field>
        </div>

        <div className="sm:col-span-2">
          <Field label="Upload D.L. or Gov't ID" required>
            <FileInput value={state.idDocument} onChange={(file) => set("idDocument", file)} />
          </Field>
        </div>
      </div>
    </div>
  );
}
