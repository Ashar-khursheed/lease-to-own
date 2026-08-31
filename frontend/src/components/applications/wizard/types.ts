import type { StepKey } from "@/components/applications/wizard/WizardSteps";

/** First message for a field from a Laravel-style { field: string[] } validation error map. */
export function fieldError(errors: Record<string, string[]> | undefined, key: string): string | undefined {
  return errors?.[key]?.[0];
}

/** Which wizard step a given API field name belongs to — used to jump straight to the step with the error. */
export const FIELD_TO_STEP: Record<string, StepKey> = {
  sales_person: "equipment",
  cash_price: "equipment",
  condition: "equipment",
  year: "equipment",
  make: "equipment",
  model: "equipment",
  serial: "equipment",
  description: "equipment",
  ldw: "equipment",
  promo_code: "equipment",

  term_months: "lease",
  tax_rate: "lease",
  monthly_rental: "lease",
  security_deposit: "lease",
  payment_due_day: "lease",
  autopay: "lease",

  registered_customer_id: "customer",
  email: "customer",
  cell_phone: "customer",
  mailing_address: "customer",
  city: "customer",
  state: "customer",
  zip: "customer",
  date_of_birth: "customer",
  drivers_license: "customer",
  id_document: "customer",

  residence_type: "risk",
  years_at_residence: "risk",
  income_source: "risk",
  gross_monthly_income: "risk",
  move_notification_agreed: "risk",
};

/** Which wizard step to jump to for the first field present in a validation error map. */
export function firstErrorStep(errors: Record<string, string[]>): StepKey | null {
  for (const key of Object.keys(errors)) {
    if (FIELD_TO_STEP[key]) return FIELD_TO_STEP[key];
  }
  return null;
}

export interface WizardState {
  // Step 1 — Equipment
  salesPerson: string;
  condition: "new" | "used";
  make: string;
  model: string;
  serial: string;
  description: string;
  ldw: "yes" | "no";
  cashPrice: string;
  year: string;
  promoCode: string;
  // Step 2 — Lease details
  termMonths: string;
  monthlyRental: string;
  taxRate: string;
  securityDeposit: string;
  paymentDueDay: string;
  autopay: "yes" | "no";
  // Step 3 — Customer info
  registeredCustomerId: string;
  email: string;
  cellPhone: string;
  mailingAddress: string;
  city: string;
  state: string;
  zip: string;
  dob: string;
  driversLicense: string;
  idDocument: File | null;
  // Step 4 — Risk & verification
  residenceType: string;
  yearsAtResidence: string;
  incomeSource: string;
  grossMonthlyIncome: string;
  moveNotificationAgreed: boolean;
}

export const INITIAL_WIZARD_STATE: WizardState = {
  salesPerson: "",
  condition: "new",
  make: "",
  model: "",
  serial: "",
  description: "",
  ldw: "yes",
  cashPrice: "",
  year: "",
  promoCode: "",
  termMonths: "36",
  monthlyRental: "",
  taxRate: "8.25",
  securityDeposit: "",
  paymentDueDay: "15th",
  autopay: "no",
  registeredCustomerId: "",
  email: "",
  cellPhone: "",
  mailingAddress: "",
  city: "",
  state: "TX",
  zip: "",
  dob: "",
  driversLicense: "",
  idDocument: null,
  residenceType: "",
  yearsAtResidence: "",
  incomeSource: "",
  grossMonthlyIncome: "",
  moveNotificationAgreed: false,
};

export function num(value: string): number {
  const n = parseFloat(value);
  return Number.isFinite(n) ? n : 0;
}

export interface LeasePricing {
  cashPrice: number;
  term: number;
  monthlyRental: number;
  salesTax: number;
  totalMonthlyPayment: number;
  totalDueToday: number;
  totalRentalPrice: number;
  epoToday: number;
  schedule: { month: number; value: number }[];
}

/**
 * Real EPO formula from the signed lease agreement (Section 3): within the
 * first 90 days (~3 monthly cycles), EPO = Cash Price − payments paid to
 * date. After that, EPO = Cash Price − 50% of payments scheduled to date +
 * payments still owed + additional funds. Taxes are due separately when the
 * EPO is exercised, not folded into this number. At the final month the
 * customer already owns the unit via the full-term path, so EPO is 0.
 */
const EPO_NINETY_DAY_MONTH_CUTOFF = 3;

function epoAtMonth(cashPrice: number, monthlyRental: number, term: number, month: number, additionalFunds = 0) {
  const m = Math.max(0, Math.min(term, month));
  if (term <= 0 || m >= term) return 0;

  const paymentsToDate = m * monthlyRental;
  if (m <= EPO_NINETY_DAY_MONTH_CUTOFF) {
    return Math.max(0, cashPrice - paymentsToDate);
  }

  const stillOwed = (term - m) * monthlyRental;
  return Math.max(0, cashPrice - 0.5 * paymentsToDate + stillOwed + additionalFunds);
}

/** Lease pricing math — mirrors the signed contract's formulas exactly (see epoAtMonth above for EPO). */
export function computeLeasePricing(state: WizardState): LeasePricing {
  const cashPrice = num(state.cashPrice);
  const term = parseInt(state.termMonths, 10) || 0;
  const monthlyRental = num(state.monthlyRental);
  const taxRate = num(state.taxRate) / 100;
  const securityDeposit = num(state.securityDeposit);

  const salesTax = monthlyRental * taxRate;
  const totalMonthlyPayment = monthlyRental + salesTax;
  const totalDueToday = totalMonthlyPayment + securityDeposit;
  const totalRentalPrice = monthlyRental * term;
  const epoAt = (month: number) => epoAtMonth(cashPrice, monthlyRental, term, month);

  const schedule: { month: number; value: number }[] = [];
  if (term > 0) {
    schedule.push({ month: 1, value: epoAt(1) });
    for (let m = 3; m <= term; m += 3) {
      schedule.push({ month: m, value: epoAt(m) });
    }
    if (schedule[schedule.length - 1]?.month !== term) {
      schedule.push({ month: term, value: epoAt(term) });
    }
  }

  return {
    cashPrice,
    term,
    monthlyRental,
    salesTax,
    totalMonthlyPayment,
    totalDueToday,
    totalRentalPrice,
    epoToday: epoAt(1),
    schedule,
  };
}

export function money(value: number): string {
  return value.toLocaleString("en-US", { style: "currency", currency: "USD" });
}
