import React, { useState, useMemo, useRef, useEffect } from "react";

/* ═══════════════════════════════════════════════════════════════════════════
   Settings — the eight reports behind the Settings nav item.

   All Master Categories is a replica of three supplied screenshots (list,
   detail panel, edit form) and sets the pattern for the rest. The other seven
   reuse that pattern with field sets read from ACCOUNTS_REBUILD_CONTEXT.md §4,
   §4.1–4.4 and §8.2 — column order and form field order for those are NOT yet
   screenshot-verified and are marked `verified: false` in each config.

   Observed in the screenshots and honoured here:
     · No Save Changes / Remove Changes on these reports — those belong to the
       inline-editable ones (Bills, Payments)
     · Booleans print as literal `true` / `false` text. No chips, no colour fill
     · Report title carries no `*` and no report-switcher chevron here, unlike
       All Payments
     · Detail panel bar holds only ‹ › then Edit / Delete / More ⌄ / ✕ — no
       record title, no status chip. Delete is a top-level button, not in More
     · Detail body is a bare 50/50 key-value table with no section heading,
       then `Add a comment`
     · The form is a near-full-screen overlay, not a centred modal, and it
       leaves a sliver of the nav rail visible at the left
     · Form field order is NOT the DS field order — the F&B checkbox sits above
       Master Category, which is declared after it
     · Edit commits with `Update`; add commits with `Submit`

   Preserved source spellings, since they are keys downstream: `Tax_Precentage`,
   `TDS_Precentage`, `Maintaince`, `Luxery`, `ACCOMODATION`.

   Block Payment Date is deliberately unbuilt. It appears in no export and no
   prior note, so its fields are unknown. Guessing it would be inventing.
   ═══════════════════════════════════════════════════════════════════════════ */

const uid = () => Math.random().toString(36).slice(2, 9);
const rid = (n) => `2924820000${String(n).padStart(8, "0")}`;

const group = (i) => {
  let last3 = i.slice(-3), rest = i.slice(0, -3);
  if (rest) last3 = "," + last3;
  return rest.replace(/\B(?=(\d{2})+(?!\d))/g, ",") + last3;
};
const money = (n) => {
  if (n === null || n === undefined || n === "" || Number.isNaN(+n)) return "";
  const [i, d] = Math.abs(+n).toFixed(2).split(".");
  return `${+n < 0 ? "-" : ""}₹ ${group(i)}.${d}`;
};
const inr = (n) => {
  if (n === "" || n === null || n === undefined || Number.isNaN(+n)) return n ?? "";
  return group(String(Math.trunc(Math.abs(+n)))) ;
};
const MA = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
const dmy = (iso) => { if (!iso) return ""; const [y, m, d] = iso.split("-"); return `${d}-${MA[+m - 1]}-${y}`; };
const parseDmy = (str) => {
  const m = /^(\d{1,2})-([A-Za-z]{3})-(\d{4})$/.exec((str || "").trim());
  if (!m) return "";
  const mi = MA.findIndex((x) => x.toLowerCase() === m[2].toLowerCase());
  return mi < 0 ? "" : `${m[3]}-${String(mi + 1).padStart(2, "0")}-${String(m[1]).padStart(2, "0")}`;
};
const bool = (v) => (v === true ? "true" : v === false ? "false" : "");

/* ── seed data ─────────────────────────────────────────────────────────── */

/* ══ REAL DATA — Creator report exports, 12-Aug-2026 ═══════════════════════
   Verbatim from the seven Settings report exports. Record IDs are kept as
   strings: they are 18 digits and lose precision as JS numbers.
   Master Categories 10 · Item Categories 135 · Approvals 9 · TDS 35 ·
   Taxes 8 · COA 144 · Auto Numbers 1.
   ════════════════════════════════════════════════════════════════════════ */

const MASTER_CATEGORIES = [
  ["Co-founders Personal", false, "", "292482000000439065"],
  ["Operations & Logistics", false, "", "292482000000361075"],
  ["Finance & Legal", false, "", "292482000000361071"],
  ["Employee & Staff Considerations", false, "", "292482000000361067"],
  ["Advertisements", false, "", "292482000000361063"],
  ["Marketing", false, "", "292482000000361059"],
  ["Technology & Communication", false, "", "292482000000361055"],
  ["New Purchases & Capex", false, "", "292482000000361051"],
  ["Property Repair & Maintenance", false, "", "292482000000213103"],
  ["F&B", true, "", "292482000000124003"],
].map(([masterCategory, fB, haewayaId, id]) => ({ id, masterCategory, fB: fB === true, haewayaId }));
const MC_NAMES = MASTER_CATEGORIES.map((m) => m.masterCategory);

/* 135 records. `F&B STAFF MEDICAL EXPENSE ` carries a trailing space in the live
   data — preserved, it is a key downstream. Expense Type is unset on 103 of 135,
   Haewaya ID on all 135, and Exclude for Observation is true on exactly one. */
const ITEM_CATEGORIES = [
  ["F&B CHEF INCENTIVE", "F&B", "", false, "", "292482000010310488", false],
  ["F&B PROFIT DISTRIBUTION", "F&B", "", false, "", "292482000010310486", false],
  ["SALES INCENTIVE", "Employee & Staff Considerations", "", false, "", "292482000010306036", false],
  ["EXPERIENCES INCENTIVE", "Employee & Staff Considerations", "Direct", false, "", "292482000010094259", false],
  ["F&B STAFF ADVANCE", "F&B", "", false, "", "292482000008940142", false],
  ["F&B STAFF LOAN", "F&B", "", false, "", "292482000008940140", false],
  ["EXPERIENCES REFUND", "Finance & Legal", "", true, "", "292482000008913100", true],
  ["STAFF ADVANCE", "Employee & Staff Considerations", "", false, "", "292482000008899006", false],
  ["CSR", "Finance & Legal", "", false, "", "292482000006888427", false],
  ["F&B GENERAL STAFF FUEL", "F&B", "Indirect", false, "", "292482000006333093", false],
  ["F&B GENERAL AUTO", "F&B", "Indirect", false, "", "292482000006333089", false],
  ["GENSET PURCHASE", "New Purchases & Capex", "", false, "", "292482000006000489", false],
  ["F&B STAFF MEDICAL EXPENSE ", "F&B", "Indirect", false, "", "292482000004346267", false],
  ["F&B CHEF OUTSOURCED", "F&B", "Direct", false, "", "292482000004346263", false],
  ["F&B OWNER PAYOUT", "F&B", "Direct", false, "", "292482000003255607", false],
  ["SECURITY DEPOSIT", "Finance & Legal", "", false, "", "292482000001720561", false],
  ["F&B AUTO", "F&B", "Indirect", false, "", "292482000001235079", false],
  ["AIRBNB MARKETING BOOKING", "Marketing", "", false, "", "292482000001193759", false],
  ["FOOD REFUND", "F&B", "", true, "", "292482000000513369", false],
  ["STAY REFUND", "Finance & Legal", "", true, "", "292482000000513345", false],
  ["TDS", "Finance & Legal", "", false, "", "292482000000479235", false],
  ["SOCIETY MAINTENANCE", "Operations & Logistics", "", false, "", "292482000000473033", false],
  ["GOVERNMENT TDS", "Finance & Legal", "", true, "", "292482000000473029", false],
  ["ZEESHAN PERSONAL", "Co-founders Personal", "", true, "", "292482000000439085", false],
  ["VARUN PERSONAL", "Co-founders Personal", "", true, "", "292482000000439081", false],
  ["INSIYA PERSONAL", "Co-founders Personal", "", false, "", "292482000000439077", false],
  ["SOHAIL PERSONAL", "Co-founders Personal", "", true, "", "292482000000439073", false],
  ["HUSAIN PERSONAL", "Co-founders Personal", "", true, "", "292482000000439069", false],
  ["FRUITS", "F&B", "Direct", false, "", "292482000000394015", false],
  ["PETTY", "Finance & Legal", "", true, "", "292482000000394007", false],
  ["INTERNAL TRANSFER", "Finance & Legal", "", true, "", "292482000000394003", false],
  ["F&B STOREROOM PURCHASE", "F&B", "Direct", true, "", "292482000000385081", false],
  ["INFLUENCER", "Marketing", "", false, "", "292482000000361493", false],
  ["TRANSPORT", "Operations & Logistics", "", false, "", "292482000000361489", false],
  ["WATER BILL", "Operations & Logistics", "", false, "", "292482000000361485", false],
  ["WATER TANKER", "Operations & Logistics", "", false, "", "292482000000361481", false],
  ["WIFI", "Operations & Logistics", "", false, "", "292482000000361477", false],
  ["STATIONARY AND DOCUMENTATION", "Operations & Logistics", "", false, "", "292482000000361473", false],
  ["TOILETRIES", "Operations & Logistics", "", false, "", "292482000000361469", false],
  ["PRINTING", "Operations & Logistics", "", false, "", "292482000000361465", false],
  ["POOL CHEMICAL", "Operations & Logistics", "", false, "", "292482000000361461", false],
  ["PEST CONTROL", "Operations & Logistics", "", false, "", "292482000000361457", false],
  ["LAUNDRY", "Operations & Logistics", "", false, "", "292482000000361453", false],
  ["HOUSEKEEPING AND CLEANING MATERIAL", "Operations & Logistics", "", false, "", "292482000000361449", false],
  ["HAMAALI AND PACKAGING", "Operations & Logistics", "", false, "", "292482000000361445", false],
  ["GUEST COMPLIMENTARY", "Operations & Logistics", "", false, "", "292482000000361441", false],
  ["GENERATOR FUEL", "Operations & Logistics", "", false, "", "292482000000361437", false],
  ["GAS CYLINDER", "Operations & Logistics", "", false, "", "292482000000361433", false],
  ["ELECTRICITY BILL", "Operations & Logistics", "", false, "", "292482000000361429", false],
  ["DTH", "Operations & Logistics", "", false, "", "292482000000361425", false],
  ["COURIER", "Operations & Logistics", "", false, "", "292482000000361421", false],
  ["OTHER TAX", "Finance & Legal", "", false, "", "292482000000361417", false],
  ["INCOME TAX", "Finance & Legal", "", false, "", "292482000000361413", false],
  ["GOVERNMENT GST", "Finance & Legal", "", false, "", "292482000000361409", false],
  ["PROFESSIONAL TAX", "Finance & Legal", "", false, "", "292482000000361405", false],
  ["ESIC", "Finance & Legal", "", false, "", "292482000000361401", false],
  ["BANK CHARGES", "Finance & Legal", "", false, "", "292482000000361397", false],
  ["PAYMENT REVERSE", "Finance & Legal", "", true, "", "292482000000361393", false],
  ["PROVIDENT FUNDS", "Finance & Legal", "", false, "", "292482000000361389", false],
  ["OWNER REVENUE", "Finance & Legal", "", false, "", "292482000000361385", false],
  ["OWNER RENT", "Finance & Legal", "", false, "", "292482000000361381", false],
  ["STAFF LOAN", "Finance & Legal", "", false, "", "292482000000361377", false],
  ["LEGAL", "Finance & Legal", "", false, "", "292482000000361373", false],
  ["HR COMPLIANCES", "Finance & Legal", "", false, "", "292482000000361369", false],
  ["INVESTMENT", "Finance & Legal", "", false, "", "292482000000361365", false],
  ["BROKERAGE", "Finance & Legal", "", false, "", "292482000000361361", false],
  ["STAFF VEHICLE MAINTENANCE", "Employee & Staff Considerations", "", false, "", "292482000000361357", false],
  ["STAFF INCENTIVES", "Employee & Staff Considerations", "", false, "", "292482000000361353", false],
  ["STAFF SALARY", "Employee & Staff Considerations", "", false, "", "292482000000361349", false],
  ["STAFF TRAVEL", "Employee & Staff Considerations", "", false, "", "292482000000361345", false],
  ["STAFF RENT & ACCOMODATION", "Employee & Staff Considerations", "", false, "", "292482000000361341", false],
  ["STAFF FUEL", "Employee & Staff Considerations", "", false, "", "292482000000361337", false],
  ["STAFF FOOD", "Employee & Staff Considerations", "", false, "", "292482000000361333", false],
  ["STAFF MEDICAL EXPENSES", "Employee & Staff Considerations", "", false, "", "292482000000361329", false],
  ["CO-FOUNDERS SPENDS", "Employee & Staff Considerations", "", false, "", "292482000000361325", false],
  ["META ADS", "Advertisements", "", false, "", "292482000000361321", false],
  ["BOOKING.COM COMMISSION", "Advertisements", "", false, "", "292482000000361317", false],
  ["GOOGLE ADS", "Advertisements", "", false, "", "292482000000361313", false],
  ["OTHER COMMISSION", "Advertisements", "", false, "", "292482000000361309", false],
  ["SEO", "Marketing", "", false, "", "292482000000361305", false],
  ["SOCIAL MEDIA SERVICES", "Marketing", "", false, "", "292482000000361301", false],
  ["PHOTOSHOOT", "Marketing", "", false, "", "292482000000361297", false],
  ["MARKETING ACTIVITIES", "Marketing", "", false, "", "292482000000361293", false],
  ["DESSERT", "F&B", "Direct", false, "", "292482000000361289", false],
  ["BAKERY", "F&B", "Direct", false, "", "292482000000361285", false],
  ["VEGETABLES", "F&B", "Direct", false, "", "292482000000361281", false],
  ["KIRANA", "F&B", "Direct", false, "", "292482000000361277", false],
  ["MEAT", "F&B", "Direct", false, "", "292482000000361273", false],
  ["DAIRY", "F&B", "Direct", false, "", "292482000000361269", false],
  ["F&B SALES INCENTIVES", "F&B", "Indirect", false, "", "292482000000361265", false],
  ["F&B UTENSILS CROCKERY", "F&B", "Indirect", false, "", "292482000000361261", false],
  ["F&B SALARY", "F&B", "Indirect", false, "", "292482000000361257", false],
  ["F&B ELECTRONICS", "F&B", "Indirect", false, "", "292482000000361253", false],
  ["F&B COMPLIMENTARY", "F&B", "Direct", false, "", "292482000000361249", false],
  ["F&B FLIPKART PURCHASE", "F&B", "Indirect", false, "", "292482000000361245", false],
  ["F&B AMAZON PURCHASE", "F&B", "Indirect", false, "", "292482000000361241", false],
  ["F&B GENERAL PURCHASE", "F&B", "Indirect", false, "", "292482000000361237", false],
  ["F&B CIVIL WORKS", "F&B", "Indirect", false, "", "292482000000361233", false],
  ["F&B GAS", "F&B", "Indirect", false, "", "292482000000361229", false],
  ["F&B REPAIR AND MAINTAINENCE", "F&B", "Indirect", false, "", "292482000000361225", false],
  ["F&B RENT AND ACCOMMODATION", "F&B", "Indirect", false, "", "292482000000361221", false],
  ["F&B VEHICLE MAINTENANCE", "F&B", "Indirect", false, "", "292482000000361217", false],
  ["F&B HOUSEKEEPING MATERIAL", "F&B", "Indirect", false, "", "292482000000361213", false],
  ["F&B TRANSPORT", "F&B", "Indirect", false, "", "292482000000361209", false],
  ["F&B STAFF FOOD", "F&B", "Indirect", false, "", "292482000000361205", false],
  ["F&B STAFF FUEL", "F&B", "Indirect", false, "", "292482000000361201", false],
  ["ELECTRONICS REPAIR", "Property Repair & Maintenance", "", false, "", "292482000000361197", false],
  ["SEPTIC TANK", "Property Repair & Maintenance", "", false, "", "292482000000361193", false],
  ["POP & FASE CEILING", "Property Repair & Maintenance", "", false, "", "292482000000361189", false],
  ["POOL WORKS", "Property Repair & Maintenance", "", false, "", "292482000000361185", false],
  ["PLUMBER WORKS", "Property Repair & Maintenance", "", false, "", "292482000000361181", false],
  ["PAINTING WORKS", "Property Repair & Maintenance", "", false, "", "292482000000361177", false],
  ["HARDWARE MATERIAL", "Property Repair & Maintenance", "", false, "", "292482000000361173", false],
  ["GENSET REPAIR", "Property Repair & Maintenance", "", false, "", "292482000000361169", false],
  ["GARDENING", "Property Repair & Maintenance", "", false, "", "292482000000361165", false],
  ["GARBAGE COLLECTION", "Property Repair & Maintenance", "", false, "", "292482000000361161", false],
  ["FABRICATION", "Property Repair & Maintenance", "", false, "", "292482000000361157", false],
  ["ELECTRICAL WORKS", "Property Repair & Maintenance", "", false, "", "292482000000361153", false],
  ["CIVIL WORK", "Property Repair & Maintenance", "", false, "", "292482000000361149", false],
  ["CARPENTRY", "Property Repair & Maintenance", "", false, "", "292482000000361145", false],
  ["AC WORKS", "Property Repair & Maintenance", "", false, "", "292482000000361141", false],
  ["DEVELOPERS", "Technology & Communication", "", false, "", "292482000000361137", false],
  ["SOFTWARE RENEWAL", "Technology & Communication", "", false, "", "292482000000361133", false],
  ["HR RECRUITMENT", "Technology & Communication", "", false, "", "292482000000361129", false],
  ["IVR", "Technology & Communication", "", false, "", "292482000000361125", false],
  ["ELECTRICAL FITTINGS", "New Purchases & Capex", "", false, "", "292482000000361121", false],
  ["FLIPKART PURCHASES", "New Purchases & Capex", "", false, "", "292482000000361117", false],
  ["UTENSILS CUTLERY CROCKERY GLASSWARE", "New Purchases & Capex", "", false, "", "292482000000361113", false],
  ["GENERAL PURCHASE", "New Purchases & Capex", "", false, "", "292482000000361109", false],
  ["LINENS", "New Purchases & Capex", "", false, "", "292482000000361105", false],
  ["HOME DECOR", "New Purchases & Capex", "", false, "", "292482000000361101", false],
  ["IKEA PURCHASES", "New Purchases & Capex", "", false, "", "292482000000361097", false],
  ["ELECTRONICS PURCHASE", "New Purchases & Capex", "", false, "", "292482000000361087", false],
  ["FURNITURE PURCHASE", "New Purchases & Capex", "", false, "", "292482000000361083", false],
  ["AMAZON PURCHASE", "New Purchases & Capex", "", false, "", "292482000000361079", false],
].map(([itemCategory, masterCategory, expenseType, excludeForProfit, haewayaId, id, excludeForObservation]) => ({
  id, itemCategory, masterCategory, expenseType, haewayaId,
  excludeForProfit: excludeForProfit === true,
  excludeForObservation: excludeForObservation === true,
  /* form-only fields — the report export does not carry these */
  variance: "", vendorName: "", coa: "", bankName: "", excludeItemCategory: false, disable: false,
}));
const ITEM_CATS_ALPHA = ITEM_CATEGORIES.map((c) => c.itemCategory).sort();

/* 35 records, only 16 distinct name + percentage pairs. Status is Active on 19 and
   BLANK on 16 — never "Expired". The Bills / Payments picker filters on Active. */
const TDS_ROWS = [
  ["Revenue Share TDS", 1, "2508841000007785095", "Active"],
  ["Revenue Share TDS", 1, "2508841000007785085", "Active"],
  ["Payment of contractors HUF/Indiv (Reduced)", 0.75, "2508841000007785169", "Active"],
  ["Payment of contractors HUF/Indiv (Reduced)", 0.75, "2508841000007785161", "Active"],
  ["Payment of contractors for Others (Reduced)", 1.5, "2508841000007785185", "Active"],
  ["Payment of contractors for Others (Reduced)", 1.5, "2508841000007785177", "Active"],
  ["Payment of contractors for Others", 2, "2508841000007785111", "Active"],
  ["Payment of contractors for Others", 2, "2508841000007785103", "Active"],
  ["Owner Rent", 10, "2508841000007785045", "Active"],
  ["Other Interest than securities (Reduced)", 7.5, "2508841000007785153", "Active"],
  ["Other Interest than securities (Reduced)", 7.5, "2508841000007785145", "Active"],
  ["Other Interest than securities (Reduced)", 7.5, "2508841000007785137", "Active"],
  ["Other Interest than securities", 10, "2508841000007785055", "Active"],
  ["Other Interest than securities", 10, "2508841000007785065", "Active"],
  ["Other Interest than securities", 10, "2508841000007785075", "Active"],
  ["NRI owner rent/ revenue", 31.2, "2508841000007785203", "Active"],
  ["Dividend (Reduced)", 7.5, "2508841000007785129", "Active"],
  ["Commission or Brokerage (Reduced)", 3.75, "2508841000007785193", "Active"],
  ["Commission or Brokerage", 2, "2508841000007785121", "Active"],
  ["NRI owner rent/ revenue", 31.2, "2508841000000280140", ""],
  ["Technical Fees (2%)", 2, "2508841000000030063", ""],
  ["Professional Fees (Reduced)", 7.5, "2508841000000030061", ""],
  ["Rent on land or furniture etc (Reduced)", 7.5, "2508841000000030059", ""],
  ["Commission or Brokerage (Reduced)", 3.75, "2508841000000030057", ""],
  ["Payment of contractors for Others (Reduced)", 1.5, "2508841000000030055", ""],
  ["Payment of contractors HUF/Indiv (Reduced)", 0.75, "2508841000000030053", ""],
  ["Other Interest than securities (Reduced)", 7.5, "2508841000000030051", ""],
  ["Dividend (Reduced)", 7.5, "2508841000000030049", ""],
  ["Professional Fees", 10, "2508841000000030047", ""],
  ["Rent on land or furniture etc", 10, "2508841000000030045", ""],
  ["Commission or Brokerage", 2, "2508841000000030043", ""],
  ["Payment of contractors for Others", 2, "2508841000000030041", ""],
  ["Revenue Share TDS", 1, "2508841000000030039", ""],
  ["Other Interest than securities", 10, "2508841000000030037", ""],
  ["Owner Rent", 10, "2508841000000030035", ""],
].map(([tdsName, tdsPrecentage, booksId, status]) => ({ id: booksId, tdsName, tdsPrecentage, booksId, status }));

const TAX_ROWS = [
  ["IGST5", "tax", 5, "2508841000000048101", "292482000003927072"],
  ["IGST18", "tax", 18, "2508841000000048105", "292482000003927070"],
  ["IGST0", "tax", 0, "2508841000000048099", "292482000003927068"],
  ["GST5", "tax_group", 5, "2508841000000048227", "292482000000130726"],
  ["GST28", "tax_group", 28, "2508841000000048251", "292482000000130724"],
  ["GST18", "tax_group", 18, "2508841000000048243", "292482000000130722"],
  ["GST12", "tax_group", 12, "2508841000000048235", "292482000000130720"],
  ["GST0", "tax_group", 0, "2508841000000048219", "292482000000130718"],
].map(([taxName, taxType, taxPrecentage, taxId, id]) => ({ id, taxName, taxType, taxPrecentage, taxId }));

/* 144 records across 16 Zoho Books account types. COA is true on 47, Bank on 44,
   and both disagree with Account Type: 25 accounts typed `bank` have Bank = false,
   while 9 with Bank = true are typed `cash` or `other_current_asset` — including
   Security Deposit. Account Code is set on 6 of 144, CA Name on 7, Account ID on 125.
   The export header reads `Bank ` with a trailing space. */
const COA_ROWS = [
  ["EKOSTAY IDFC LLP", "bank", "", "2508841000001975413", true, true, "", "292482000007582002", ""],
  ["Tax witholding - Premsagar", "bank", "", "", false, false, "", "292482000007527415", "120"],
  ["Sabiha Tax withholding", "bank", "", "", false, false, "", "292482000007527413", "116"],
  ["Copacabana- Varun Arora", "bank", "", "", false, false, "", "292482000007527411", "87"],
  ["Tax withholding- Kotak LLP 1", "bank", "", "", false, false, "", "292482000007527409", "79"],
  ["Salma Kaleem (Kotak Mahindra Bank)", "bank", "", "", false, false, "", "292482000007527407", "59"],
  ["Siraj Axis Bank", "bank", "", "", false, false, "", "292482000007527405", "46"],
  ["Sabiha Kotak", "bank", "", "", false, false, "", "292482000007527403", "27"],
  ["Cash with Varun", "bank", "", "", false, false, "", "292482000007527401", "47"],
  ["Zareena", "bank", "", "", false, false, "", "292482000007527399", "33"],
  ["Natasha ICICI Bank", "bank", "", "", false, false, "", "292482000007527397", "20"],
  ["Tax withholding - Nishrin", "bank", "", "", false, false, "", "292482000007527395", "114"],
  ["tax withholding LLP 2", "bank", "", "", false, false, "", "292482000007527393", "93"],
  ["Dattaram Shinde", "bank", "", "", false, false, "", "292482000007527391", "61"],
  ["tax withholding Hosp", "bank", "", "", false, false, "", "292482000007527389", "115"],
  ["Tax withholding Copacabana", "bank", "", "", false, false, "", "292482000007527387", "98"],
  ["Concorde Petrochemical Industries Co", "bank", "", "", false, false, "", "292482000007527385", "86"],
  ["Tax withholding- Farida", "bank", "", "", false, false, "", "292482000007527383", "78"],
  ["Amatulla Mustafa Fanuswala (KOTAK MAHINDRA)", "bank", "", "", false, false, "", "292482000007527381", "54"],
  ["IDFC Hospitality LLP", "bank", "", "2508841000006678616", true, true, "", "292482000006023012", "122"],
  ["Owner Bank", "bank", "", "2508841000003546120", false, true, "", "292482000003513115", "25"],
  ["Staff Loan", "bank", "", "2508841000002987314", true, true, "", "292482000002989969", ""],
  ["EKOSTAY IDFC LLP", "bank", "", "", false, false, "", "292482000002001441", "123"],
  ["VARUN RAJAN ARORA", "bank", "", "2508841000000660171", false, false, "", "292482000000881004", ""],
  ["ZAINAB IQBAL BANGLAWALA", "bank", "", "2508841000000660137", true, true, "", "292482000000881002", "108"],
  ["Co founder Personal", "other_asset", "", "2508841000000617025", true, false, "", "292482000000819205", ""],
  ["MUSTAFA TAUFIQUE FANUSWALA", "bank", "", "2508841000000519171", true, true, "", "292482000000807002", "62"],
  ["EKOSTAY LLP 2", "bank", "", "2508841000000374059", true, true, "Jitesh", "292482000000509004", "26"],
  ["Husain Cash", "cash", "", "2508841000000359045", true, true, "", "292482000000509002", "23"],
  ["EKOSTAY Mazgaon HDFC", "bank", "", "2508841000000304637", true, true, "", "292482000000505044", "10"],
  ["PREMSAGAR SHARMA", "bank", "", "2508841000000304767", true, true, "", "292482000000505042", "119"],
  ["HASAN TIPUSULTAN AMRELIA", "bank", "", "2508841000000304761", true, true, "", "292482000000505040", "107"],
  ["Zainab Iqbal Banglawala", "bank", "", "2508841000000304755", false, false, "", "292482000000505038", ""],
  ["Nishrin Patanwala - Kotak Mahindra Bank", "bank", "", "2508841000000304749", true, true, "", "292482000000505036", "110"],
  ["Murtuza Bhavnagarwala", "bank", "", "2508841000000304743", true, true, "", "292482000000505034", "92"],
  ["Abdul Rehman Firoz Ahmad Ansari ( HDFC)", "bank", "", "2508841000000304737", true, true, "", "292482000000505032", "94"],
  ["Burhan Fakhruddin Khatumdi", "bank", "", "2508841000000304731", true, true, "", "292482000000505030", "85"],
  ["Kavita Kashinath Patil (Kotak)", "bank", "", "2508841000000304725", true, true, "", "292482000000505028", "74"],
  ["Shahnawaz Khan", "bank", "", "2508841000000304719", true, true, "", "292482000000505026", "65"],
  ["Mariyam Toufeeq Fanuswala (kotak)", "bank", "", "2508841000000304713", true, true, "", "292482000000505024", "63"],
  ["Chetna Mirchandani (Kotak Mahindra Bank)", "bank", "", "2508841000000304703", true, true, "", "292482000000505022", "57"],
  ["HUSAIN ICICI BANK", "bank", "", "2508841000000304697", true, true, "", "292482000000505020", "50"],
  ["FARIDA ICICI BANK", "bank", "", "2508841000000304691", true, true, "", "292482000000505018", "31"],
  ["Anita Kotak Mahindra", "bank", "", "2508841000000304685", true, true, "", "292482000000505016", "29"],
  ["Renu Sethi Kotak Mahindra (7839)", "bank", "", "2508841000000304679", true, true, "", "292482000000505014", "28"],
  ["3 ACES TRAVELS LLP", "bank", "", "2508841000000304673", true, true, "", "292482000000505012", "21"],
  ["Varun Kotak Mahindra Current", "bank", "", "2508841000000304667", true, true, "", "292482000000505010", "18"],
  ["Sohail Kotak Mahindra Current", "bank", "", "2508841000000304661", true, true, "", "292482000000505008", "17"],
  ["Rajan Arora Kotak Mahindra", "bank", "", "2508841000000304655", true, true, "", "292482000000505006", "16"],
  ["HDFC - Kayam Chunawala", "bank", "", "2508841000000304649", true, true, "", "292482000000505004", "13"],
  ["HDFC - Insiya Khatumdi", "bank", "", "2508841000000304643", true, true, "", "292482000000505002", "12"],
  ["IGST TDS Payable", "other_current_liability", "", "2508841000000280276", false, false, "", "292482000000447024", ""],
  ["SGST TDS Payable", "other_current_liability", "", "2508841000000280260", false, false, "", "292482000000447022", ""],
  ["CGST TDS Payable", "other_current_liability", "", "2508841000000280244", false, false, "", "292482000000447020", ""],
  ["Intermediate TDS Payable", "other_current_liability", "", "2508841000000280225", false, false, "", "292482000000447018", ""],
  ["Ground Bank", "cash", "", "2508841000000264449", true, true, "", "292482000000447016", ""],
  ["Petty Cash Ooty", "cash", "", "2508841000000264435", true, true, "", "292482000000447014", ""],
  ["Petty Cash Gufran", "cash", "", "2508841000000264429", true, true, "", "292482000000447012", ""],
  ["Petty Cash Goa", "cash", "", "2508841000000264407", true, true, "", "292482000000447010", ""],
  ["Petty Cash Alibaug", "cash", "", "2508841000000264401", true, true, "", "292482000000447008", ""],
  ["IGST TDS Receivable", "other_current_asset", "", "2508841000000280284", false, false, "", "292482000000447006", ""],
  ["SGST TDS Receivable", "other_current_asset", "", "2508841000000280268", false, false, "", "292482000000447004", ""],
  ["CGST TDS Receivable", "other_current_asset", "", "2508841000000280252", false, false, "", "292482000000447002", ""],
  ["Hold Salary Payable", "other_current_liability", "Payroll-006", "2508841000000277290", false, false, "", "292482000000441388", ""],
  ["Net Salary Payable", "other_current_liability", "Payroll-005", "2508841000000277287", false, false, "", "292482000000441386", ""],
  ["Deductions Payable", "other_current_liability", "Payroll-004", "2508841000000277284", false, false, "", "292482000000441384", ""],
  ["Statutory Deductions Payable", "other_current_liability", "Payroll-003", "2508841000000277281", false, false, "", "292482000000441382", ""],
  ["Payroll Tax Payable", "other_current_liability", "Payroll-002", "2508841000000277278", false, false, "", "292482000000441380", ""],
  ["Reimbursements Payable", "other_current_liability", "Payroll-001", "2508841000000277275", false, false, "", "292482000000441378", ""],
  ["Zoho Payroll - Bank Account", "bank", "", "2508841000000277343", false, false, "", "292482000000441376", ""],
  ["Haewaya EKOSTAY Hospitality", "bank", "", "2508841000000218145", true, true, "Jitesh", "292482000000419360", ""],
  ["Haewaya EKOSTAY LLP", "bank", "", "2508841000000218139", true, true, "Jitesh", "292482000000419358", ""],
  ["Mustafa Account", "bank", "", "2508841000000218109", false, false, "", "292482000000389192", ""],
  ["EKOSTAY HOSPITALITY LLP", "bank", "", "2508841000000218103", true, true, "Jitesh", "292482000000389190", "84"],
  ["EKOSTAY LLP 1", "bank", "", "2508841000000218097", true, true, "Jitesh", "292482000000389188", "8"],
  ["Ekostay LLP 2 old", "bank", "", "2508841000000218089", false, false, "Keshav", "292482000000389186", ""],
  ["Aliakber", "bank", "", "2508841000000218121", true, true, "", "292482000000389184", "73"],
  ["Toufeeq Turabali Fanuswala", "bank", "", "2508841000000218115", true, true, "", "292482000000389182", "64"],
  ["F&B Store Room Purchase", "other_current_asset", "", "2508841000000218127", false, false, "", "292482000000389180", ""],
  ["Inventory Asset", "stock", "", "2508841000000000626", false, false, "", "292482000000251482", ""],
  ["Exchange Gain or Loss", "other_expense", "", "2508841000000000513", false, false, "", "292482000000251480", ""],
  ["Cost of Goods Sold", "cost_of_goods_sold", "", "2508841000000000567", false, false, "", "292482000000251478", ""],
  ["Credit Card Charges", "expense", "", "2508841000000000510", false, false, "", "292482000000251476", ""],
  ["Expense", "expense", "", "2508841000000161277", true, false, "", "292482000000251474", ""],
  ["Bank Fees and Charges", "expense", "", "2508841000000000507", false, false, "", "292482000000251472", ""],
  ["Purchase Discounts", "expense", "", "2508841000000011001", false, false, "", "292482000000251470", ""],
  ["Uncategorized", "expense", "", "2508841000000000576", false, false, "", "292482000000251468", ""],
  ["Lodging", "expense", "", "2508841000000000564", false, false, "", "292482000000251466", ""],
  ["Other Expenses", "expense", "", "2508841000000000558", false, false, "", "292482000000251464", ""],
  ["Meals and Entertainment", "expense", "", "2508841000000000546", false, false, "", "292482000000251462", ""],
  ["Salaries and Employee Wages", "expense", "", "2508841000000000543", false, false, "", "292482000000251460", ""],
  ["Payment Reverse", "other_expense", "", "2508841000000161311", true, false, "", "292482000000251458", ""],
  ["Bad Debt", "expense", "", "2508841000000000537", false, false, "", "292482000000251456", ""],
  ["Customer Refunds", "expense", "", "2508841000000161299", false, false, "", "292482000000251454", ""],
  ["IT and Internet Expenses", "expense", "", "2508841000000000525", false, false, "", "292482000000251452", ""],
  ["Automobile Expense", "expense", "", "2508841000000000522", false, false, "", "292482000000251450", ""],
  ["Telephone Expense", "expense", "", "2508841000000000519", false, false, "", "292482000000251448", ""],
  ["Travel Expense", "expense", "", "2508841000000000516", false, false, "", "292482000000251446", ""],
  ["Discount", "income", "", "2508841000000000504", false, false, "", "292482000000251444", ""],
  ["Late Fee Income", "income", "", "2508841000000000495", false, false, "", "292482000000251442", ""],
  ["Interest Income", "income", "", "2508841000000000492", false, false, "", "292482000000251440", ""],
  ["General Income", "income", "", "2508841000000000489", false, false, "", "292482000000251438", ""],
  ["Sales", "income", "", "2508841000000000486", false, false, "", "292482000000251436", ""],
  ["Shipping Charge", "income", "", "2508841000000000622", false, false, "", "292482000000251434", ""],
  ["Other Charges", "income", "", "2508841000000000619", false, false, "", "292482000000251432", ""],
  ["Opening Balance Offset", "equity", "", "2508841000000000483", false, false, "", "292482000000251430", ""],
  ["Owner's Equity", "equity", "", "2508841000000000480", false, false, "", "292482000000251428", ""],
  ["Retained Earnings", "equity", "", "2508841000000000477", false, false, "", "292482000000251426", ""],
  ["Dividends Paid", "equity", "", "2508841000000030113", false, false, "", "292482000000251424", ""],
  ["Capital Stock", "equity", "", "2508841000000030111", false, false, "", "292482000000251422", ""],
  ["Distributions", "equity", "", "2508841000000030101", false, false, "", "292482000000251420", ""],
  ["Investments", "equity", "", "2508841000000030099", false, false, "", "292482000000251418", ""],
  ["Drawings", "equity", "", "2508841000000000561", false, false, "", "292482000000251416", ""],
  ["Dimension Adjustments", "other_liability", "", "2508841000000000579", false, false, "", "292482000000251414", ""],
  ["Construction Loans", "long_term_liability", "", "2508841000000030097", false, false, "", "292482000000251412", ""],
  ["Mortgages", "long_term_liability", "", "2508841000000030095", false, false, "", "292482000000251410", ""],
  ["Accounts Payable", "accounts_payable", "", "2508841000000000471", true, false, "", "292482000000251408", ""],
  ["Tax Payable", "other_current_liability", "", "2508841000000000474", false, false, "", "292482000000251406", ""],
  ["Output SGST", "other_current_liability", "", "2508841000000048085", false, false, "", "292482000000251404", ""],
  ["Output CGST", "other_current_liability", "", "2508841000000048069", false, false, "", "292482000000251402", ""],
  ["Output IGST", "other_current_liability", "", "2508841000000048045", false, false, "", "292482000000251400", ""],
  ["GST Payable", "other_current_liability", "", "2508841000000048037", false, false, "", "292482000000251398", ""],
  ["Unearned Revenue", "other_current_liability", "", "2508841000000000617", false, false, "", "292482000000251396", ""],
  ["Opening Balance Adjustments", "other_current_liability", "", "2508841000000000615", false, false, "", "292482000000251394", ""],
  ["TDS Payable", "other_current_liability", "", "2508841000000030019", false, false, "", "292482000000251392", ""],
  ["Employee Reimbursements", "other_current_liability", "", "2508841000000000573", false, false, "", "292482000000251390", ""],
  ["Furniture and Equipment", "fixed_asset", "", "2508841000000000465", false, false, "", "292482000000251388", ""],
  ["Accounts Receivable", "accounts_receivable", "", "2508841000000000462", false, false, "", "292482000000251386", ""],
  ["EKOSTAY LLP ICICI", "bank", "", "2508841000000080103", true, true, "Jitesh", "292482000000251384", "60"],
  ["Internal Transfer", "bank", "", "2508841000000161293", false, false, "", "292482000000251382", ""],
  ["Petty Cash Igatpuri", "cash", "", "2508841000000161019", true, true, "", "292482000000251380", ""],
  ["Petty Cash Lonavala", "cash", "", "2508841000000161013", true, true, "", "292482000000251378", ""],
  ["Petty Cash", "cash", "", "2508841000000000459", false, false, "", "292482000000251376", ""],
  ["Undeposited Funds", "cash", "", "2508841000000000456", false, false, "", "292482000000251374", ""],
  ["Advance Tax", "other_current_asset", "", "2508841000000000468", false, false, "", "292482000000251372", ""],
  ["Input SGST", "other_current_asset", "", "2508841000000048093", false, false, "", "292482000000251370", ""],
  ["Input CGST", "other_current_asset", "", "2508841000000048077", false, false, "", "292482000000251368", ""],
  ["Input IGST", "other_current_asset", "", "2508841000000048061", false, false, "", "292482000000251366", ""],
  ["Input Tax Credits", "other_current_asset", "", "2508841000000048053", false, false, "", "292482000000251364", ""],
  ["Reverse Charge Tax Input but not due", "other_current_asset", "", "2508841000000048013", false, false, "", "292482000000251362", ""],
  ["TDS Receivable", "other_current_asset", "", "2508841000000030015", false, false, "", "292482000000251360", ""],
  ["Employee Advance", "other_current_asset", "", "2508841000000000570", false, false, "", "292482000000251358", ""],
  ["Prepaid Expenses", "other_current_asset", "", "2508841000000030009", false, false, "", "292482000000251356", ""],
  ["Security Deposit", "other_current_asset", "", "2508841000000161285", true, true, "", "292482000000251354", ""],
].map(([accountName, accountType, accountCode, accountId, coa, bank, caName, id, ekostayId]) => ({
  id, accountName, accountType, accountCode, accountId, caName, ekostayId,
  coa: coa === true, bank: bank === true,
}));

/* One record. Books Payment No has never advanced past 1. External Payment Series
   and No are form/detail fields, not report columns, so the export omits them —
   those two values come from the detail screenshot. */
const AUTO_NUMBERS = [{
  id: "292482000000132217",
  paymentSeries: "EKS/PY", paymentNo: 20938,
  haewayaSeries: "EKS/Haewaya", haewayaNo: 32010,
  booksPaymentSeries: "EKS/BPY", booksPaymentNo: 1,
  externalPaymentSeries: "EKS/API", externalPaymentNo: 88,
}];


/* The location and villa masters, recovered from the Approvals export — 10
   locations and 204 villa names. Note `Head Office Central` is in use as a
   Location value, and the villa master carries `Nature` three times plus a test
   record named `fcgfhbjnh`, eight names with leading spaces and three with
   doubled spaces. Preserved as-is; they are keys downstream. */
const ALL_LOCATIONS = ["Panchgani", "Igatpuri", "Kodaikanal", "Ooty And Coonoor", "Goa", "Bangalore", "Alibaug", "Lonavala", "Karjat", "Head Office Central"];
const ALL_VILLAS = [
  "Casablanca Villa", "Hercules Villa", "Black Mirror Villa", "Amber Villa", "Woodside Ivy Villa",
  "Winterfell Chalet Villa", "Pearl House", "Moonlight Villa", "Whispering Clouds",
  "Whispering Clouds @ 1 Room", "Villa Eve", "Skyfall Dew Drops", "Casa Del Sol", "Garden Grove Villa",
  "Dutch Cottage", "Skyfall Cloud 9 Villa", "Skyfall Mist Villa", "Skyfall Elysium Villa", "Ooty Central",
  "Morning Dew Villa", "Apollo Villa", "Helios Villa", "Echo Villa", "Under The Pines", "Dusk Villa",
  "Dawn Villa", "Orchid Villa", "Whispering Pines", "Over the Stream Villa", "Divine Villa",
  "The Velvet Slope", "Casa Porto- Anjuna", "Casa Vaga- Vagator", "Ocean Crest Villa- Anjuna Beach",
  "Saltwater Villa- Nerul", "Oasis Villa - Saligaon", "Signature Apartment - Candolim",
  "Solace Villa- Candolim", "Green Door Apartment - Candolim", "Woodlock Apartment - Candolim",
  "Athens Villa  Nerul", "Bliss Apartment - Candolim", "Casa Pino- Pilerne", "Casa Marina 6BHK- Nerul",
  "Casa Marina 2BHK- Nerul", "Casa Marina 4BHK- Nerul", "Amore Apartment- Siolim",
  "Copacabana Villa Calangute", "Avante Villa- Calangute", " Utopian Villa- Pilerene",
  "Blue Tiffany Villa- Nerul", "Belvedere Apartment", " Ezra Villa- Anjuna", "Grey Goose Villa- Calangute",
  "Casa Beleza- Nerul", "Peach Grove Villa- Arpora", "Ivory Goose Villa- Calangute",
  "Velvet Goose Villa- Calangute", "Goa Central", "Copacabana Villa- Calangute", "Jade Villa- Pilerne",
  "Coconut Grove Villa- Parra", "Aqua Beach Villa- Candolim Beach", " House of Eight", "Assa Villa- Assagaon",
  "Olive Goose Villa- Calangute", " Luna Goose Villa- Calangute", "Hawaiian Villa", "Pinewood Villa",
  "EKOSTAY- Bali Villa", "Casa Royale", "Lakefront Villa", "Panorama Villa", "Casa Polo",
  "Mount Emerald Villa", "EKOSTAY- Villa Blanco", "Hill View Villa", "EKOSTAY- Sucasa Villa",
  "EKOSTAY- Kingfisher Villa", "Sky Villa", "Blue Pebble Villa", "Paramount Villa", "Titanium Villa",
  "EKOSTAY- Deltin Villa", "EKOSTAY- Tropicana Villa (Nama)", "Tropicana Villa (Aum)", "Octagon Villa",
  "Brickstone Villa", "EKOSTAY- Nest Villa", "Eclipse Villa", "EKOSTAY- Simba Villa", "Chestnut Villa",
  "Casa Zul", "Olive Crest", "Gatsby Villa", "Tropicana Villa(Shivay)", "Casa Paradiso",
  "EKOSTAY- Morning Star Villa", "Forestwood Villa", "Windsor  Villa", "Neptune Villa", "Iris Villa",
  "Casa De Aria", "Aqua Villa", "Omega Villa", "Alpha Villa", "Highland Villa 7BHK", "Bloomfield Villa",
  "Amara Villa", "Blue Door Villa", "Ivory Coast Villa", "Casa Roma", "CASA SIA", "Cliffside Villa",
  "EKOSTAY- Oceanic Villa", "Serene Villa", "Palm Estate", "Amani Villa", "Woodstock Villa 8BHK",
  "Sea Shore Villa 8 BHK", "Sea Shore Villa 12BHK", "Casa De Reva", "Jungle Beach 12 BHK",
  "Jungle Beach 8 BHK", "Woodstock Villa 6BHK", "Fairmonte Villa", "Highland Villa 6BHK", "La Casa Grande",
  "Costa Brava Villa", "Casa Vayu", "White Oak Villa", "Casa Amado", "La Flamingo", "Cosy Cove Villa",
  "Gravity Villa", "Casa Enzo Villa", "Casa Coconut", "White Swan Villa", "Oakwood Chalet",
  "Amanta Hills 7BHK", "Amanta Green 8BHK", "Amanta Palms 6BHK", "EKOSTAY -Amanta Villa 15BHK",
  "Amanta Villa 21BHK", "Infinity Villa", "Driftwood Villa", "Serenity Villa", "Ivana Villa", "Eternal Wave",
  "Alpine Villa", "Noir Villa", "Casa Den", "Azure Villa", "Shelby Villa", "Harmony Heights",
  "Golden Oasis Villa", "Hazelnut Villa", "Venus Villa", "Mercury Villa", "Saturn Villa", "White Lotus Villa",
  "Casa Noah", "Secret Garden Villa", "Cherry Blossom", "Santo Maria Villa", "Ark Stone Villa",
  "Alibaug Central", "Lonavla Central", "Igatpuri Central", "Karjat Central", "Panchgani Central",
  "Head Office Central", "Pebble Villa", "Tranquil Villa", "Hazel Villa", "Woodpecker Villa", "Nature",
  "Twilight Villa", "AshBee Villa", "Kara Villa", "Terra Cotta Villa", "The Clay House", "7 Palms",
  "StarMount  Villa", "Kihim 6BHK", "Bellwood Villa", "Aurus Villa", "Brick Wood", "Trinity Villa",
  "Grey Horizon", "Ecstasy Villa", "Santorini Villa", "Concrete Cove Villa", "fcgfhbjnh", "Sea Lagoon Villa",
  " Casa Bella", "Air Villa (4BHK)", " Casa Elara", " Milo Duplex Villa", "Silo Duplex Villa",
  "Caprica duplex Villa", "Corica Duplex Villa", " Brooklyn Villa", "Greco Villa"
];

const APPROVER_OPTIONS = ["Rohan - rohan.ops@ekostay.com", "Husain - husain@ekostay.com",
  "Zeeshan - zeeshan@ekostay.com", "Priya - priya@ekostay.com"];

/* 9 records. The export carries seven columns; `Exclude Category` and `Type` are
   form/detail fields only, so they are known for record 1 alone (from the
   screenshot) and left blank elsewhere rather than guessed. Approvers rows carry
   only their Level from the export — record 1 has the real bands and approvers.
   Level 1 & 2 Approval is unset on three records and Level 2 & 3 on five. */
const APPROVALS = [
  {
    id: rid(610004), module: "Payment",
    level12: "Any", level23: "Any",
    location: ["Panchgani", "Igatpuri"],
    villaName: [
      "Casablanca Villa", "Hercules Villa", "Black Mirror Villa"
    ],
    itemCategory: [
      "AMAZON PURCHASE", "FURNITURE PURCHASE", "ELECTRONICS PURCHASE", "IKEA PURCHASES", "HOME DECOR",
      "LINENS", "GENERAL PURCHASE", "UTENSILS CUTLERY CROCKERY GLASSWARE", "FLIPKART PURCHASES",
      "ELECTRICAL FITTINGS", "IVR", "HR RECRUITMENT", "SOFTWARE RENEWAL", "DEVELOPERS", "AC WORKS",
      "CARPENTRY", "CIVIL WORK", "ELECTRICAL WORKS", "FABRICATION", "GARBAGE COLLECTION", "GARDENING",
      "GENSET REPAIR", "HARDWARE MATERIAL", "PAINTING WORKS", "PLUMBER WORKS", "POOL WORKS",
      "POP & FASE CEILING", "SEPTIC TANK", "ELECTRONICS REPAIR", "F&B STAFF FUEL", "F&B STAFF FOOD",
      "F&B TRANSPORT", "F&B HOUSEKEEPING MATERIAL", "F&B VEHICLE MAINTENANCE", "F&B RENT AND ACCOMMODATION",
      "F&B REPAIR AND MAINTAINENCE", "F&B GAS", "F&B CIVIL WORKS", "F&B GENERAL PURCHASE",
      "F&B AMAZON PURCHASE", "F&B FLIPKART PURCHASE", "F&B COMPLIMENTARY", "F&B ELECTRONICS", "F&B SALARY",
      "F&B UTENSILS CROCKERY", "F&B SALES INCENTIVES", "DAIRY", "MEAT", "KIRANA", "VEGETABLES", "BAKERY",
      "DESSERT", "MARKETING ACTIVITIES", "PHOTOSHOOT", "SOCIAL MEDIA SERVICES", "SEO", "OTHER COMMISSION",
      "GOOGLE ADS", "BOOKING.COM COMMISSION", "META ADS", "CO-FOUNDERS SPENDS", "STAFF MEDICAL EXPENSES",
      "STAFF FOOD", "STAFF FUEL", "STAFF RENT & ACCOMODATION", "STAFF TRAVEL", "STAFF SALARY",
      "STAFF INCENTIVES", "STAFF VEHICLE MAINTENANCE", "BROKERAGE", "INVESTMENT", "HR COMPLIANCES", "LEGAL",
      "STAFF LOAN", "OWNER RENT", "OWNER REVENUE", "PROVIDENT FUNDS", "PAYMENT REVERSE", "BANK CHARGES",
      "ESIC", "PROFESSIONAL TAX", "GOVERNMENT GST", "INCOME TAX", "OTHER TAX", "COURIER", "DTH",
      "ELECTRICITY BILL", "GAS CYLINDER", "GENERATOR FUEL", "GUEST COMPLIMENTARY", "HAMAALI AND PACKAGING",
      "HOUSEKEEPING AND CLEANING MATERIAL", "LAUNDRY", "PEST CONTROL", "POOL CHEMICAL", "PRINTING",
      "TOILETRIES", "STATIONARY AND DOCUMENTATION", "WIFI", "WATER TANKER", "WATER BILL", "TRANSPORT",
      "INFLUENCER", "F&B STOREROOM PURCHASE", "INTERNAL TRANSFER", "PETTY", "FRUITS", "HUSAIN PERSONAL",
      "SOHAIL PERSONAL", "INSIYA PERSONAL", "VARUN PERSONAL", "ZEESHAN PERSONAL", "GOVERNMENT TDS",
      "SOCIETY MAINTENANCE", "TDS", "STAY REFUND", "FOOD REFUND", "AIRBNB MARKETING BOOKING", "F&B AUTO",
      "SECURITY DEPOSIT", "F&B OWNER PAYOUT", "F&B CHEF OUTSOURCED", "F&B STAFF MEDICAL EXPENSE ",
      "GENSET PURCHASE", "F&B GENERAL AUTO", "F&B GENERAL STAFF FUEL", "CSR", "STAFF ADVANCE",
      "EXPERIENCES REFUND", "F&B STAFF LOAN", "F&B STAFF ADVANCE"
    ],
    excludeCategory: ["EXPERIENCES INCENTIVE", "SALES INCENTIVE"], typeField: "Include",
    approvers: [
      { id: uid(), level: "Level 1", minimumAmount: 2000, maximumAmount: 5000,
        approver: ["Rohan - rohan.ops@ekostay.com"], approvalType: "" },
      { id: uid(), level: "Level 2", minimumAmount: 5001, maximumAmount: 500000000,
        approver: ["Husain - husain@ekostay.com"], approvalType: "Any" },
    ],
  },
  {
    id: rid(610008), module: "Payment",
    level12: "", level23: "",
    location: ["Kodaikanal"],
    villaName: [
      "Amber Villa"
    ],
    itemCategory: [
      "OWNER RENT", "OWNER REVENUE"
    ],
    excludeCategory: [], typeField: "",
    approvers: [{ id: uid(), level: "Level 1", minimumAmount: "", maximumAmount: "", approver: [], approvalType: "" }],
  },
  {
    id: rid(610012), module: "Payment",
    level12: "Any", level23: "",
    location: ["Ooty And Coonoor", "Kodaikanal"],
    villaName: [
      "Woodside Ivy Villa", "Winterfell Chalet Villa", "Pearl House", "Moonlight Villa", "Whispering Clouds",
      "Amber Villa", "Whispering Clouds @ 1 Room", "Villa Eve", "Skyfall Dew Drops", "Casa Del Sol",
      "Garden Grove Villa", "Dutch Cottage", "Skyfall Cloud 9 Villa", "Skyfall Mist Villa",
      "Skyfall Elysium Villa", "Ooty Central", "Morning Dew Villa", "Apollo Villa", "Helios Villa",
      "Echo Villa", "Under The Pines", "Dusk Villa", "Dawn Villa", "Orchid Villa", "Whispering Pines",
      "Over the Stream Villa", "Divine Villa", "The Velvet Slope"
    ],
    itemCategory: [
      "CO-FOUNDERS SPENDS"
    ],
    excludeCategory: [], typeField: "",
    approvers: [{ id: uid(), level: "Level 1", minimumAmount: "", maximumAmount: "", approver: [], approvalType: "" }, { id: uid(), level: "Level 2", minimumAmount: "", maximumAmount: "", approver: [], approvalType: "" }],
  },
  {
    id: rid(610016), module: "Payment",
    level12: "Any", level23: "Any",
    location: ["Kodaikanal"],
    villaName: [
      "Amber Villa"
    ],
    itemCategory: [
      "AMAZON PURCHASE", "FURNITURE PURCHASE", "ELECTRONICS PURCHASE", "IKEA PURCHASES", "HOME DECOR",
      "LINENS", "GENERAL PURCHASE", "UTENSILS CUTLERY CROCKERY GLASSWARE", "FLIPKART PURCHASES",
      "ELECTRICAL FITTINGS", "IVR", "HR RECRUITMENT", "SOFTWARE RENEWAL", "DEVELOPERS", "AC WORKS",
      "CARPENTRY", "CIVIL WORK", "ELECTRICAL WORKS", "FABRICATION", "GARBAGE COLLECTION", "GARDENING",
      "GENSET REPAIR", "HARDWARE MATERIAL", "PAINTING WORKS", "PLUMBER WORKS", "POOL WORKS",
      "POP & FASE CEILING", "SEPTIC TANK", "ELECTRONICS REPAIR", "F&B STAFF FUEL", "F&B STAFF FOOD",
      "F&B TRANSPORT", "F&B HOUSEKEEPING MATERIAL", "F&B VEHICLE MAINTENANCE", "F&B RENT AND ACCOMMODATION",
      "F&B REPAIR AND MAINTAINENCE", "F&B GAS", "F&B CIVIL WORKS", "F&B GENERAL PURCHASE",
      "F&B AMAZON PURCHASE", "F&B FLIPKART PURCHASE", "F&B COMPLIMENTARY", "F&B ELECTRONICS", "F&B SALARY",
      "F&B UTENSILS CROCKERY", "F&B SALES INCENTIVES", "DAIRY", "MEAT", "KIRANA", "VEGETABLES", "BAKERY",
      "DESSERT", "MARKETING ACTIVITIES", "PHOTOSHOOT", "SOCIAL MEDIA SERVICES", "SEO", "OTHER COMMISSION",
      "GOOGLE ADS", "BOOKING.COM COMMISSION", "META ADS", "STAFF MEDICAL EXPENSES", "STAFF FOOD",
      "STAFF FUEL", "STAFF RENT & ACCOMODATION", "STAFF TRAVEL", "STAFF SALARY", "STAFF INCENTIVES",
      "STAFF VEHICLE MAINTENANCE", "BROKERAGE", "INVESTMENT", "HR COMPLIANCES", "LEGAL", "STAFF LOAN",
      "PROVIDENT FUNDS", "PAYMENT REVERSE", "BANK CHARGES", "ESIC", "PROFESSIONAL TAX", "GOVERNMENT GST",
      "INCOME TAX", "OTHER TAX", "COURIER", "DTH", "ELECTRICITY BILL", "GAS CYLINDER", "GENERATOR FUEL",
      "GUEST COMPLIMENTARY", "HAMAALI AND PACKAGING", "HOUSEKEEPING AND CLEANING MATERIAL", "LAUNDRY",
      "PEST CONTROL", "POOL CHEMICAL", "PRINTING", "TOILETRIES", "STATIONARY AND DOCUMENTATION", "WIFI",
      "WATER TANKER", "WATER BILL", "TRANSPORT", "INFLUENCER", "F&B STOREROOM PURCHASE", "INTERNAL TRANSFER",
      "PETTY", "FRUITS", "HUSAIN PERSONAL", "SOHAIL PERSONAL", "INSIYA PERSONAL", "VARUN PERSONAL",
      "ZEESHAN PERSONAL", "GOVERNMENT TDS", "SOCIETY MAINTENANCE", "TDS", "STAY REFUND", "FOOD REFUND",
      "AIRBNB MARKETING BOOKING", "F&B AUTO", "SECURITY DEPOSIT", "F&B OWNER PAYOUT", "F&B CHEF OUTSOURCED",
      "F&B STAFF MEDICAL EXPENSE ", "GENSET PURCHASE", "F&B GENERAL AUTO", "F&B GENERAL STAFF FUEL", "CSR",
      "STAFF ADVANCE", "EXPERIENCES REFUND", "F&B STAFF LOAN", "F&B STAFF ADVANCE"
    ],
    excludeCategory: [], typeField: "",
    approvers: [{ id: uid(), level: "Level 1", minimumAmount: "", maximumAmount: "", approver: [], approvalType: "" }, { id: uid(), level: "Level 2", minimumAmount: "", maximumAmount: "", approver: [], approvalType: "" }],
  },
  {
    id: rid(610020), module: "Payment",
    level12: "Any", level23: "",
    location: ["Ooty And Coonoor", "Kodaikanal"],
    villaName: [
      "Woodside Ivy Villa", "Winterfell Chalet Villa", "Pearl House", "Moonlight Villa", "Whispering Clouds",
      "Whispering Clouds @ 1 Room", "Villa Eve", "Skyfall Dew Drops", "Casa Del Sol", "Garden Grove Villa",
      "Dutch Cottage", "Skyfall Cloud 9 Villa", "Skyfall Mist Villa", "Skyfall Elysium Villa",
      "Ooty Central", "Morning Dew Villa", "Apollo Villa", "Helios Villa", "Echo Villa", "Under The Pines",
      "Dusk Villa", "Dawn Villa", "Orchid Villa", "Whispering Pines", "Over the Stream Villa",
      "Divine Villa", "The Velvet Slope"
    ],
    itemCategory: [
      "AMAZON PURCHASE", "FURNITURE PURCHASE", "ELECTRONICS PURCHASE", "IKEA PURCHASES", "HOME DECOR",
      "LINENS", "GENERAL PURCHASE", "UTENSILS CUTLERY CROCKERY GLASSWARE", "FLIPKART PURCHASES",
      "ELECTRICAL FITTINGS", "IVR", "HR RECRUITMENT", "SOFTWARE RENEWAL", "DEVELOPERS", "AC WORKS",
      "CARPENTRY", "CIVIL WORK", "ELECTRICAL WORKS", "FABRICATION", "GARBAGE COLLECTION", "GARDENING",
      "GENSET REPAIR", "HARDWARE MATERIAL", "PAINTING WORKS", "PLUMBER WORKS", "POOL WORKS",
      "POP & FASE CEILING", "SEPTIC TANK", "ELECTRONICS REPAIR", "F&B STAFF FUEL", "F&B STAFF FOOD",
      "F&B TRANSPORT", "F&B HOUSEKEEPING MATERIAL", "F&B VEHICLE MAINTENANCE", "F&B RENT AND ACCOMMODATION",
      "F&B REPAIR AND MAINTAINENCE", "F&B GAS", "F&B CIVIL WORKS", "F&B GENERAL PURCHASE",
      "F&B AMAZON PURCHASE", "F&B FLIPKART PURCHASE", "F&B COMPLIMENTARY", "F&B ELECTRONICS", "F&B SALARY",
      "F&B UTENSILS CROCKERY", "F&B SALES INCENTIVES", "DAIRY", "MEAT", "KIRANA", "VEGETABLES", "BAKERY",
      "DESSERT", "MARKETING ACTIVITIES", "PHOTOSHOOT", "SOCIAL MEDIA SERVICES", "SEO", "OTHER COMMISSION",
      "GOOGLE ADS", "BOOKING.COM COMMISSION", "META ADS", "STAFF MEDICAL EXPENSES", "STAFF FOOD",
      "STAFF FUEL", "STAFF RENT & ACCOMODATION", "STAFF TRAVEL", "STAFF SALARY", "STAFF INCENTIVES",
      "STAFF VEHICLE MAINTENANCE", "BROKERAGE", "INVESTMENT", "HR COMPLIANCES", "LEGAL", "STAFF LOAN",
      "OWNER RENT", "OWNER REVENUE", "PROVIDENT FUNDS", "PAYMENT REVERSE", "BANK CHARGES", "ESIC",
      "PROFESSIONAL TAX", "GOVERNMENT GST", "INCOME TAX", "OTHER TAX", "COURIER", "DTH", "ELECTRICITY BILL",
      "GAS CYLINDER", "GENERATOR FUEL", "GUEST COMPLIMENTARY", "HAMAALI AND PACKAGING",
      "HOUSEKEEPING AND CLEANING MATERIAL", "LAUNDRY", "PEST CONTROL", "POOL CHEMICAL", "PRINTING",
      "TOILETRIES", "STATIONARY AND DOCUMENTATION", "WIFI", "WATER TANKER", "WATER BILL", "TRANSPORT",
      "INFLUENCER", "F&B STOREROOM PURCHASE", "INTERNAL TRANSFER", "PETTY", "FRUITS", "HUSAIN PERSONAL",
      "SOHAIL PERSONAL", "INSIYA PERSONAL", "VARUN PERSONAL", "ZEESHAN PERSONAL", "GOVERNMENT TDS",
      "SOCIETY MAINTENANCE", "TDS", "STAY REFUND", "FOOD REFUND", "AIRBNB MARKETING BOOKING", "F&B AUTO",
      "SECURITY DEPOSIT", "F&B OWNER PAYOUT", "F&B CHEF OUTSOURCED", "F&B STAFF MEDICAL EXPENSE ",
      "GENSET PURCHASE", "F&B GENERAL AUTO", "F&B GENERAL STAFF FUEL", "CSR", "STAFF ADVANCE",
      "EXPERIENCES REFUND", "F&B STAFF LOAN", "F&B STAFF ADVANCE"
    ],
    excludeCategory: [], typeField: "",
    approvers: [{ id: uid(), level: "Level 1", minimumAmount: "", maximumAmount: "", approver: [], approvalType: "" }, { id: uid(), level: "Level 2", minimumAmount: "", maximumAmount: "", approver: [], approvalType: "" }],
  },
  {
    id: rid(610024), module: "Payment",
    level12: "", level23: "",
    location: ["Goa"],
    villaName: [
      "Casa Porto- Anjuna", "Casa Vaga- Vagator", "Ocean Crest Villa- Anjuna Beach",
      "Saltwater Villa- Nerul", "Oasis Villa - Saligaon", "Signature Apartment - Candolim",
      "Solace Villa- Candolim", "Green Door Apartment - Candolim", "Woodlock Apartment - Candolim",
      "Athens Villa  Nerul", "Bliss Apartment - Candolim", "Casa Pino- Pilerne", "Casa Marina 6BHK- Nerul",
      "Casa Marina 2BHK- Nerul", "Casa Marina 4BHK- Nerul", "Amore Apartment- Siolim",
      "Copacabana Villa Calangute", "Avante Villa- Calangute", " Utopian Villa- Pilerene",
      "Blue Tiffany Villa- Nerul", "Belvedere Apartment", " Ezra Villa- Anjuna",
      "Grey Goose Villa- Calangute", "Casa Beleza- Nerul", "Peach Grove Villa- Arpora",
      "Ivory Goose Villa- Calangute", "Velvet Goose Villa- Calangute", "Goa Central",
      "Copacabana Villa- Calangute", "Jade Villa- Pilerne", "Coconut Grove Villa- Parra",
      "Aqua Beach Villa- Candolim Beach", " House of Eight", "Assa Villa- Assagaon",
      "Olive Goose Villa- Calangute", " Luna Goose Villa- Calangute"
    ],
    itemCategory: [
      "AMAZON PURCHASE", "F&B RENT AND ACCOMMODATION", "F&B AMAZON PURCHASE", "F&B SALARY",
      "CO-FOUNDERS SPENDS", "STAFF RENT & ACCOMODATION", "STAFF SALARY", "STAFF LOAN", "OWNER RENT",
      "OWNER REVENUE", "ELECTRICITY BILL", "WATER BILL", "SOCIETY MAINTENANCE", "STAY REFUND", "FOOD REFUND",
      "EXPERIENCES REFUND", "F&B STAFF LOAN"
    ],
    excludeCategory: [], typeField: "",
    approvers: [{ id: uid(), level: "Level 1", minimumAmount: "", maximumAmount: "", approver: [], approvalType: "" }],
  },
  {
    id: rid(610028), module: "Payment",
    level12: "All", level23: "All",
    location: ["Bangalore"],
    villaName: [
      "Hawaiian Villa"
    ],
    itemCategory: [
      "WIFI"
    ],
    excludeCategory: [], typeField: "",
    approvers: [{ id: uid(), level: "Level 1", minimumAmount: "", maximumAmount: "", approver: [], approvalType: "" }],
  },
  {
    id: rid(610032), module: "Payment",
    level12: "Any", level23: "Any",
    location: ["Goa"],
    villaName: [
      "Casa Porto- Anjuna", "Casa Vaga- Vagator", "Ocean Crest Villa- Anjuna Beach",
      "Saltwater Villa- Nerul", "Oasis Villa - Saligaon", "Signature Apartment - Candolim",
      "Solace Villa- Candolim", "Green Door Apartment - Candolim", "Woodlock Apartment - Candolim",
      "Athens Villa  Nerul", "Bliss Apartment - Candolim", "Casa Pino- Pilerne", "Casa Marina 6BHK- Nerul",
      "Casa Marina 2BHK- Nerul", "Casa Marina 4BHK- Nerul", "Amore Apartment- Siolim",
      "Copacabana Villa Calangute", "Avante Villa- Calangute", " Utopian Villa- Pilerene",
      "Blue Tiffany Villa- Nerul", "Belvedere Apartment", " Ezra Villa- Anjuna",
      "Grey Goose Villa- Calangute", "Casa Beleza- Nerul", "Peach Grove Villa- Arpora",
      "Ivory Goose Villa- Calangute", "Velvet Goose Villa- Calangute", "Goa Central",
      "Copacabana Villa- Calangute", "Jade Villa- Pilerne", "Coconut Grove Villa- Parra",
      "Aqua Beach Villa- Candolim Beach", " House of Eight", "Assa Villa- Assagaon",
      "Olive Goose Villa- Calangute", " Luna Goose Villa- Calangute"
    ],
    itemCategory: [
      "FURNITURE PURCHASE", "ELECTRONICS PURCHASE", "IKEA PURCHASES", "HOME DECOR", "LINENS",
      "GENERAL PURCHASE", "UTENSILS CUTLERY CROCKERY GLASSWARE", "FLIPKART PURCHASES", "ELECTRICAL FITTINGS",
      "IVR", "HR RECRUITMENT", "SOFTWARE RENEWAL", "DEVELOPERS", "AC WORKS", "CARPENTRY", "CIVIL WORK",
      "ELECTRICAL WORKS", "FABRICATION", "GARBAGE COLLECTION", "GARDENING", "GENSET REPAIR",
      "HARDWARE MATERIAL", "PAINTING WORKS", "PLUMBER WORKS", "POOL WORKS", "POP & FASE CEILING",
      "SEPTIC TANK", "ELECTRONICS REPAIR", "F&B STAFF FUEL", "F&B STAFF FOOD", "F&B TRANSPORT",
      "F&B HOUSEKEEPING MATERIAL", "F&B VEHICLE MAINTENANCE", "F&B REPAIR AND MAINTAINENCE", "F&B GAS",
      "F&B CIVIL WORKS", "F&B GENERAL PURCHASE", "F&B FLIPKART PURCHASE", "F&B COMPLIMENTARY",
      "F&B ELECTRONICS", "F&B UTENSILS CROCKERY", "F&B SALES INCENTIVES", "DAIRY", "MEAT", "KIRANA",
      "VEGETABLES", "BAKERY", "DESSERT", "MARKETING ACTIVITIES", "PHOTOSHOOT", "SOCIAL MEDIA SERVICES",
      "SEO", "OTHER COMMISSION", "GOOGLE ADS", "BOOKING.COM COMMISSION", "META ADS",
      "STAFF MEDICAL EXPENSES", "STAFF FOOD", "STAFF FUEL", "STAFF TRAVEL", "STAFF INCENTIVES",
      "STAFF VEHICLE MAINTENANCE", "BROKERAGE", "INVESTMENT", "HR COMPLIANCES", "LEGAL", "PROVIDENT FUNDS",
      "PAYMENT REVERSE", "BANK CHARGES", "ESIC", "PROFESSIONAL TAX", "GOVERNMENT GST", "INCOME TAX",
      "OTHER TAX", "COURIER", "DTH", "GAS CYLINDER", "GENERATOR FUEL", "GUEST COMPLIMENTARY",
      "HAMAALI AND PACKAGING", "HOUSEKEEPING AND CLEANING MATERIAL", "LAUNDRY", "PEST CONTROL",
      "POOL CHEMICAL", "PRINTING", "TOILETRIES", "STATIONARY AND DOCUMENTATION", "WIFI", "WATER TANKER",
      "TRANSPORT", "INFLUENCER", "F&B STOREROOM PURCHASE", "INTERNAL TRANSFER", "PETTY", "FRUITS",
      "HUSAIN PERSONAL", "SOHAIL PERSONAL", "INSIYA PERSONAL", "VARUN PERSONAL", "ZEESHAN PERSONAL",
      "GOVERNMENT TDS", "TDS", "AIRBNB MARKETING BOOKING", "F&B AUTO", "SECURITY DEPOSIT",
      "F&B OWNER PAYOUT", "F&B CHEF OUTSOURCED", "F&B STAFF MEDICAL EXPENSE ", "GENSET PURCHASE",
      "F&B GENERAL AUTO", "F&B GENERAL STAFF FUEL", "CSR", "STAFF ADVANCE", "F&B STAFF ADVANCE"
    ],
    excludeCategory: [], typeField: "",
    approvers: [{ id: uid(), level: "Level 1", minimumAmount: "", maximumAmount: "", approver: [], approvalType: "" }, { id: uid(), level: "Level 2", minimumAmount: "", maximumAmount: "", approver: [], approvalType: "" }],
  },
  {
    id: rid(610036), module: "Payment",
    level12: "", level23: "",
    location: ["Alibaug", "Lonavala", "Panchgani", "Igatpuri", "Karjat", "Head Office Central"],
    villaName: [
      "Pinewood Villa", "EKOSTAY- Bali Villa", "Casa Royale", "Lakefront Villa", "Panorama Villa",
      "Casa Polo", "Mount Emerald Villa", "EKOSTAY- Villa Blanco", "Hill View Villa",
      "EKOSTAY- Sucasa Villa", "EKOSTAY- Kingfisher Villa", "Sky Villa", "Blue Pebble Villa",
      "Paramount Villa", "Titanium Villa", "EKOSTAY- Deltin Villa", "EKOSTAY- Tropicana Villa (Nama)",
      "Tropicana Villa (Aum)", "Octagon Villa", "Brickstone Villa", "EKOSTAY- Nest Villa", "Eclipse Villa",
      "EKOSTAY- Simba Villa", "Chestnut Villa", "Casa Zul", "Olive Crest", "Gatsby Villa",
      "Tropicana Villa(Shivay)", "Casa Paradiso", "EKOSTAY- Morning Star Villa", "Forestwood Villa",
      "Windsor  Villa", "Neptune Villa", "Iris Villa", "Casa De Aria", "Aqua Villa", "Omega Villa",
      "Alpha Villa", "Highland Villa 7BHK", "Bloomfield Villa", "Amara Villa", "Blue Door Villa",
      "Ivory Coast Villa", "Casa Roma", "CASA SIA", "Cliffside Villa", "EKOSTAY- Oceanic Villa",
      "Serene Villa", "Palm Estate", "Amani Villa", "Woodstock Villa 8BHK", "Sea Shore Villa 8 BHK",
      "Sea Shore Villa 12BHK", "Casa De Reva", "Jungle Beach 12 BHK", "Jungle Beach 8 BHK",
      "Woodstock Villa 6BHK", "Fairmonte Villa", "Highland Villa 6BHK", "La Casa Grande",
      "Costa Brava Villa", "Casa Vayu", "White Oak Villa", "Casa Amado", "La Flamingo", "Cosy Cove Villa",
      "Gravity Villa", "Casa Enzo Villa", "Casa Coconut", "White Swan Villa", "Oakwood Chalet",
      "Amanta Hills 7BHK", "Amanta Green 8BHK", "Amanta Palms 6BHK", "EKOSTAY -Amanta Villa 15BHK",
      "Amanta Villa 21BHK", "Infinity Villa", "Driftwood Villa", "Serenity Villa", "Ivana Villa",
      "Eternal Wave", "Alpine Villa", "Noir Villa", "Casa Den", "Azure Villa", "Shelby Villa",
      "Harmony Heights", "Golden Oasis Villa", "Hazelnut Villa", "Venus Villa", "Mercury Villa",
      "Saturn Villa", "White Lotus Villa", "Casa Noah", "Secret Garden Villa", "Cherry Blossom",
      "Santo Maria Villa", "Ark Stone Villa", "Alibaug Central", "Lonavla Central", "Igatpuri Central",
      "Karjat Central", "Panchgani Central", "Head Office Central", "Pebble Villa", "Tranquil Villa",
      "Hazel Villa", "Woodpecker Villa", "Nature", "Nature", "Nature", "Twilight Villa", "AshBee Villa",
      "Kara Villa", "Terra Cotta Villa", "The Clay House", "7 Palms", "StarMount  Villa", "Kihim 6BHK",
      "Bellwood Villa", "Aurus Villa", "Brick Wood", "Trinity Villa", "Grey Horizon", "Ecstasy Villa",
      "Santorini Villa", "Concrete Cove Villa", "fcgfhbjnh", "Sea Lagoon Villa", " Casa Bella",
      "Air Villa (4BHK)", " Casa Elara", " Milo Duplex Villa", "Silo Duplex Villa", "Caprica duplex Villa",
      "Corica Duplex Villa", " Brooklyn Villa", "Greco Villa"
    ],
    itemCategory: [
      "AMAZON PURCHASE", "FURNITURE PURCHASE", "ELECTRONICS PURCHASE", "IKEA PURCHASES", "HOME DECOR",
      "LINENS", "GENERAL PURCHASE", "UTENSILS CUTLERY CROCKERY GLASSWARE", "FLIPKART PURCHASES",
      "ELECTRICAL FITTINGS", "IVR", "HR RECRUITMENT", "SOFTWARE RENEWAL", "DEVELOPERS", "AC WORKS",
      "CARPENTRY", "CIVIL WORK", "ELECTRICAL WORKS", "FABRICATION", "GARBAGE COLLECTION", "GARDENING",
      "GENSET REPAIR", "HARDWARE MATERIAL", "PAINTING WORKS", "PLUMBER WORKS", "POOL WORKS",
      "POP & FASE CEILING", "SEPTIC TANK", "ELECTRONICS REPAIR", "F&B STAFF FUEL", "F&B STAFF FOOD",
      "F&B TRANSPORT", "F&B HOUSEKEEPING MATERIAL", "F&B VEHICLE MAINTENANCE", "F&B RENT AND ACCOMMODATION",
      "F&B REPAIR AND MAINTAINENCE", "F&B GAS", "F&B CIVIL WORKS", "F&B GENERAL PURCHASE",
      "F&B AMAZON PURCHASE", "F&B FLIPKART PURCHASE", "F&B COMPLIMENTARY", "F&B ELECTRONICS", "F&B SALARY",
      "F&B UTENSILS CROCKERY", "F&B SALES INCENTIVES", "DAIRY", "MEAT", "KIRANA", "VEGETABLES", "BAKERY",
      "DESSERT", "MARKETING ACTIVITIES", "PHOTOSHOOT", "SOCIAL MEDIA SERVICES", "SEO", "OTHER COMMISSION",
      "GOOGLE ADS", "BOOKING.COM COMMISSION", "META ADS", "CO-FOUNDERS SPENDS", "STAFF MEDICAL EXPENSES",
      "STAFF FOOD", "STAFF FUEL", "STAFF RENT & ACCOMODATION", "STAFF TRAVEL", "STAFF SALARY",
      "STAFF INCENTIVES", "STAFF VEHICLE MAINTENANCE", "BROKERAGE", "INVESTMENT", "HR COMPLIANCES", "LEGAL",
      "STAFF LOAN", "OWNER RENT", "OWNER REVENUE", "PROVIDENT FUNDS", "PAYMENT REVERSE", "BANK CHARGES",
      "ESIC", "PROFESSIONAL TAX", "GOVERNMENT GST", "INCOME TAX", "OTHER TAX", "COURIER", "DTH",
      "ELECTRICITY BILL", "GAS CYLINDER", "GENERATOR FUEL", "GUEST COMPLIMENTARY", "HAMAALI AND PACKAGING",
      "HOUSEKEEPING AND CLEANING MATERIAL", "LAUNDRY", "PEST CONTROL", "POOL CHEMICAL", "PRINTING",
      "TOILETRIES", "STATIONARY AND DOCUMENTATION", "WIFI", "WATER TANKER", "WATER BILL", "TRANSPORT",
      "INFLUENCER", "F&B STOREROOM PURCHASE", "INTERNAL TRANSFER", "PETTY", "FRUITS", "HUSAIN PERSONAL",
      "SOHAIL PERSONAL", "INSIYA PERSONAL", "VARUN PERSONAL", "ZEESHAN PERSONAL", "GOVERNMENT TDS",
      "SOCIETY MAINTENANCE", "TDS", "STAY REFUND", "FOOD REFUND", "AIRBNB MARKETING BOOKING", "F&B AUTO",
      "SECURITY DEPOSIT", "F&B OWNER PAYOUT", "F&B CHEF OUTSOURCED", "F&B STAFF MEDICAL EXPENSE ",
      "GENSET PURCHASE", "F&B GENERAL AUTO", "F&B GENERAL STAFF FUEL", "CSR", "STAFF ADVANCE",
      "EXPERIENCES REFUND", "F&B STAFF LOAN", "F&B STAFF ADVANCE", "EXPERIENCES INCENTIVE",
      "SALES INCENTIVE"
    ],
    excludeCategory: [], typeField: "",
    approvers: [{ id: uid(), level: "Level 1", minimumAmount: "", maximumAmount: "", approver: [], approvalType: "" }],
  },
];


/* Verbatim from the Block Payment Date screenshot. A singleton — the report holds
   exactly one record and the form refuses a second. The date is the cutoff: payments
   before it are blocked. Audit fields are exposed as report columns here, which they
   are on no other Settings report. */
const BLOCK_PAYMENT_DATE = [{
  id: rid(780004), date: "2026-06-01",
  addedUser: "husain_ekostay1", addedTime: "12-Aug-2026 19:19:31",
  modifiedUser: "husain_ekostay1", modifiedTime: "12-Aug-2026 19:19:31",
}];

/* ── report configs ─────────────────────────────────────────────────────── */

const T = { text: "text", bool: "bool", num: "num", pct: "pct", money: "money", id: "id", list: "list", date: "date" };

const REPORTS = {
  masterCategories: {
    nav: "All Master Categories", title: "All Master Categories", formTitle: "Master Category",
    verified: true, seed: MASTER_CATEGORIES,
    columns: [
      { k: "masterCategory", label: "Master Category", w: 420 },
      { k: "fB", label: "F&B", w: 280, type: T.bool },
      { k: "haewayaId", label: "Haewaya ID", w: 300 },
      { k: "id", label: "ID", w: 300, type: T.id },
    ],
    detail: ["masterCategory", "fB", "haewayaId"],
    /* form order per the screenshot: the checkbox sits above the text fields */
    form: [
      { k: "fB", label: "F&B", type: T.bool },
      { k: "masterCategory", label: "Master Category" },
      { k: "haewayaId", label: "Haewaya ID" },
    ],
    blank: { masterCategory: "", fB: false, haewayaId: "" },
    search: ["Master Category", "Haewaya ID"],
  },

  itemCategories: {
    nav: "All Item Categories", title: "All Item Categories", formTitle: "Item Category",
    verified: true, seed: ITEM_CATEGORIES,
    /* Column order verbatim from the screenshot — note ID sits mid-set, not last.
       Everything from Variance rightward is beyond the captured viewport and is
       ordered to follow the detail panel. [TODO] confirm. */
    /* Column set settled by the export — seven columns, ID sixth. The fields on the
       detail panel and form beyond these (Variance, Vendor Name, Exclude Item
       Category, COA, Bank Name, Disallow Manual Creation) are not report columns. */
    columns: [
      { k: "itemCategory", label: "Item Category", w: 340 },
      { k: "masterCategory", label: "Master Category", w: 250 },
      { k: "expenseType", label: "Expense Type", w: 130 },
      { k: "excludeForProfit", label: "Exclude for Profit", w: 140, type: T.bool },
      { k: "haewayaId", label: "Haewaya ID", w: 140 },
      { k: "id", label: "ID", w: 210, type: T.id },
      { k: "excludeForObservation", label: "Exclude for Observation", w: 180, type: T.bool },
    ],
    /* Detail field order verbatim from the screenshot. */
    detail: ["itemCategory", "masterCategory", "expenseType", "excludeForProfit", "haewayaId",
      "excludeForObservation", "variance", "vendorName", "excludeItemCategory", "coa", "bankName", "disable"],
    /* Form field order verbatim from the screenshot — checkboxes are interleaved
       in source order, NOT grouped at the top. `Disable` carries the display
       label "Disallow Manual Creation". */
    form: [
      { k: "itemCategory", label: "Item Category" },
      { k: "masterCategory", label: "Master Category", type: "lookup", options: MC_NAMES },
      { k: "vendorName", label: "Vendor Name", type: "lookup", options: [],
        hint: "If vendor is selected, items will be visible only for the selected vendor." },
      { k: "coa", label: "COA", type: "lookup", options: COA_ROWS.filter((c) => c.coa).map((c) => c.accountName) },
      { k: "bankName", label: "Bank Name", type: "lookup", options: COA_ROWS.filter((c) => c.bank).map((c) => c.accountName) },
      { k: "expenseType", label: "Expense Type", type: "lookup", options: ["Direct", "Indirect"] },
      { k: "excludeForProfit", label: "Exclude for Profit", type: T.bool },
      { k: "excludeForObservation", label: "Exclude for Observation", type: T.bool },
      { k: "variance", label: "Variance", type: T.num, suffix: "%" },
      { k: "haewayaId", label: "Haewaya ID" },
      { k: "excludeItemCategory", label: "Exclude Item Category", type: T.bool },
      { k: "disable", label: "Disallow Manual Creation", type: T.bool },
    ],
    blank: { itemCategory: "", masterCategory: "", vendorName: "", coa: "", bankName: "", expenseType: "",
      excludeForProfit: false, excludeForObservation: false, variance: "", haewayaId: "",
      excludeItemCategory: false, disable: false },
    search: ["Item Category", "Master Category", "COA", "Expense Type"],
  },

  approvals: {
    nav: "All Approvals", title: "All Approvals", formTitle: "Approval",
    verified: true, seed: APPROVALS, subform: "approvers",
    /* Multi-select values render one per line, so row height is content-driven —
       the first record fills the viewport on its own. */
    stacked: true,
    /* Column set settled by the export — seven columns, no Exclude Category, no
       Type, no ID. Those three are detail/form fields only. */
    columns: [
      { k: "module", label: "Module", w: 120 },
      { k: "level12", label: "Level 1 & 2 Approval", w: 200 },
      { k: "level23", label: "Level 2 & 3 Approval", w: 200 },
      { k: "approvers", label: "Approvers", w: 130, type: T.list,
        derive: (r) => (r.approvers ?? []).map((x) => x.level) },
      { k: "location", label: "Location", w: 220, type: T.list },
      { k: "villaName", label: "Villa Name", w: 300, type: T.list },
      { k: "itemCategory", label: "Item Category", w: 380, type: T.list },
    ],
    /* Detail field order verbatim. Approvers renders as its Level values only. */
    detail: ["module", "level12", "level23", "approvers", "location", "villaName",
      "itemCategory", "excludeCategory", "typeField"],
    /* Two-column form: Module / Location / Villa Name / Type / Item Category on the
       left, the two Level fields alone on the right. Type is a radio pair. */
    twoCol: true,
    form: [
      { k: "module", label: "Module", type: "lookup", options: ["Payment"] },
      { k: "location", label: "Location", type: "multi", options: ALL_LOCATIONS },
      { k: "villaName", label: "Villa Name", type: "multi", options: ALL_VILLAS },
      { k: "typeField", label: "Type", type: "radio", options: ["Include", "Exclude"] },
      { k: "itemCategory", label: "Item Category", type: "multi", options: ITEM_CATS_ALPHA, scroll: true },
      { k: "excludeCategory", label: "Exclude Category", type: "multi", options: ITEM_CATS_ALPHA },
    ],
    formRight: [
      { k: "level12", label: "Level 1 & 2 Approval", type: "lookup", options: ["Any", "All"] },
      { k: "level23", label: "Level 2 & 3 Approval", type: "lookup", options: ["Any", "All"] },
    ],
    subformTitle: "Approvers",
    subformAdd: "Add New",
    subformCols: [
      { k: "level", label: "Level", type: "lookup", options: ["Level 1", "Level 2", "Level 3"], w: 170 },
      { k: "minimumAmount", label: "Minimum Amount", type: "rupee", w: 170 },
      { k: "maximumAmount", label: "Maximum Amount", type: "rupee", w: 170 },
      { k: "approver", label: "Approver", type: "multi", options: APPROVER_OPTIONS, w: 250 },
      { k: "approvalType", label: "Approval Type", type: "lookup", options: ["Any", "All"], w: 150 },
    ],
    subformBlank: { level: "", minimumAmount: "", maximumAmount: "", approver: [], approvalType: "" },
    blank: { module: "Payment", location: [], villaName: [], typeField: "", itemCategory: [],
      excludeCategory: [], level12: "", level23: "", approvers: [] },
    search: ["Module", "Type", "Location"],
  },

  tds: {
    nav: "TDS Report", title: "TDS Report", formTitle: "TDS",
    verified: true, seed: TDS_ROWS,
    /* Display label is "TDS Percentage", correctly spelled, while the underlying
       field stays `TDS_Precentage`. No ID column on this report. */
    columns: [
      { k: "tdsName", label: "TDS Name", w: 470 },
      { k: "tdsPrecentage", label: "TDS Percentage", w: 190, type: T.pct, dp: 2 },
      { k: "booksId", label: "Books ID", w: 260, type: T.num },
      { k: "status", label: "Status", w: 200 },
    ],
    detail: ["tdsName", "tdsPrecentage", "booksId", "status"],
    form: [
      { k: "tdsName", label: "TDS Name" },
      { k: "tdsPrecentage", label: "TDS Percentage", type: T.num, suffix: "%" },
      { k: "booksId", label: "Books ID" },
      { k: "status", label: "Status", type: "lookup", options: ["Active", "Expired"] },  /* 16 of 35 records hold blank */
    ],
    blank: { tdsName: "", tdsPrecentage: "", booksId: "", status: "" },
    search: ["TDS Name", "Status"],
  },

  taxes: {
    nav: "All Taxes", title: "All Taxes", formTitle: "Tax",
    verified: true, seed: TAX_ROWS,
    columns: [
      { k: "taxName", label: "Tax Name", w: 240 },
      { k: "taxType", label: "Tax Type", w: 240 },
      { k: "taxPrecentage", label: "Tax Percentage", w: 320, type: T.pct, dp: 2 },
      { k: "taxId", label: "Tax ID", w: 460, type: T.num },
      { k: "id", label: "ID", w: 300, type: T.id },
    ],
    /* ID appears as a column but not as a detail field. */
    detail: ["taxName", "taxType", "taxPrecentage", "taxId"],
    /* Tax Type is a free-text input, not a picklist — nothing constrains it to
       the two Books values the live data holds. */
    form: [
      { k: "taxName", label: "Tax Name" },
      { k: "taxType", label: "Tax Type" },
      { k: "taxPrecentage", label: "Tax Percentage", type: T.num, suffix: "%" },
      { k: "taxId", label: "Tax ID" },
    ],
    blank: { taxName: "", taxType: "", taxPrecentage: "", taxId: "" },
    search: ["Tax Name", "Tax Type"],
  },

  coa: {
    nav: "COA Report", title: "COA Report", formTitle: "COA",
    verified: true, seed: COA_ROWS,
    /* The only Settings report that is inline-editable — it carries the `*` after
       the title plus Save Changes / Remove Changes, as Bills and Payments do. */
    savable: true, star: true,
    /* `COA` is a boolean on both the report and the form, and is absent from the §4
       field list. `Hide` is in that list and absent from both screens. Most likely
       `COA` is the display label for `Hide` — the same label-diverges-from-field
       pattern as TDS Percentage / TDS_Precentage. [TODO] confirm in the form builder.
       If so, §7.5 needs correcting: the Payments picker's COA[Hide == true] is not
       inverted, it means "only real chart-of-accounts entries". */
    columns: [
      { k: "accountName", label: "Account Name", w: 300 },
      { k: "accountType", label: "Account Type", w: 150 },
      { k: "accountCode", label: "Account Code", w: 140 },
      { k: "accountId", label: "Account ID", w: 220, type: T.num },
      { k: "coa", label: "COA", w: 110, type: T.bool },
      { k: "bank", label: "Bank", w: 110, type: T.bool },
      { k: "caName", label: "CA Name", w: 150 },
      { k: "id", label: "ID", w: 200, type: T.id },
      { k: "ekostayId", label: "Ekostay ID", w: 120, type: T.num },
    ],
    detail: ["accountName", "accountType", "accountCode", "accountId", "coa", "bank", "caName", "ekostayId"],
    /* Account Type is a free-text input, like Tax Type. CA Name is a lookup. */
    form: [
      { k: "coa", label: "COA", type: T.bool },
      { k: "accountName", label: "Account Name" },
      { k: "accountType", label: "Account Type" },
      { k: "accountCode", label: "Account Code" },
      { k: "accountId", label: "Account ID" },
      { k: "bank", label: "Bank", type: T.bool },
      { k: "caName", label: "CA Name", type: "lookup", options: ["Jitesh", "Keshav"] },
      { k: "ekostayId", label: "Ekostay ID" },
    ],
    blank: { accountName: "", accountType: "", accountCode: "", accountId: "", coa: false, bank: false,
      caName: "", ekostayId: "" },
    search: ["Account Name", "Account Type", "CA Name"],
  },

  blockPaymentDate: {
    nav: "Block Payment Date", title: "Block Payment Date", formTitle: "Block Payment Date",
    verified: true, seed: BLOCK_PAYMENT_DATE,
    /* One record only. The ＋ button raises Creator's own message rather than a form. */
    singleton: true,
    singletonMessage: "Please Edit in same record you cannot add new record",
    toast: "Block Payment Added Successfully",
    columns: [
      { k: "date", label: "Date", w: 260, type: T.date },
      { k: "addedUser", label: "Added User", w: 310 },
      { k: "addedTime", label: "Added Time", w: 400 },
      { k: "modifiedUser", label: "Modified User", w: 300 },
      { k: "modifiedTime", label: "Modified Time", w: 380 },
    ],
    detail: ["date", "addedUser", "addedTime", "modifiedUser", "modifiedTime"],
    /* [TODO] the form itself was not captured. Assumed to be the single Date field;
       the audit fields are Creator system fields and are not editable. */
    formVerified: false,
    form: [{ k: "date", label: "Date", type: T.date }],
    blank: { date: "", addedUser: "husain_ekostay1", addedTime: "", modifiedUser: "husain_ekostay1", modifiedTime: "" },
    search: ["Added User", "Modified User"],
  },

  autoNumbers: {
    nav: "Auto Numbers", title: "Auto Numbers", formTitle: "Auto Numbers",
    verified: true, seed: AUTO_NUMBERS,
    /* The list omits External Payment Series / No — they appear only on the detail
       panel and the form. */
    columns: [
      { k: "paymentSeries", label: "Payment Series", w: 220 },
      { k: "paymentNo", label: "Payment No", w: 190, type: T.num },
      { k: "booksPaymentSeries", label: "Books Payment Series", w: 290 },
      { k: "booksPaymentNo", label: "Books Payment No", w: 200, type: T.num },
      { k: "haewayaSeries", label: "Haewaya Series", w: 210, type: T.text },
      { k: "haewayaNo", label: "Haewaya No", w: 190, type: T.num },
      { k: "id", label: "ID", w: 300, type: T.id },
    ],
    detail: ["paymentSeries", "paymentNo", "booksPaymentSeries", "booksPaymentNo",
      "haewayaSeries", "haewayaNo", "externalPaymentSeries", "externalPaymentNo"],
    /* The only form of the eight with section headings — and Haewaya is grouped
       under Payment rather than standing on its own. */
    form: [
      { section: "Payment" },
      { k: "paymentSeries", label: "Payment Series" },
      { k: "paymentNo", label: "Payment No", type: T.num },
      { k: "haewayaSeries", label: "Haewaya Series" },
      { k: "haewayaNo", label: "Haewaya No", type: T.num },
      { section: "Books Payment" },
      { k: "booksPaymentSeries", label: "Books Payment Series" },
      { k: "booksPaymentNo", label: "Books Payment No", type: T.num },
      { section: "External Payment" },
      { k: "externalPaymentSeries", label: "External Payment Series" },
      { k: "externalPaymentNo", label: "External Payment No", type: T.num },
    ],
    blank: { paymentSeries: "", paymentNo: "", booksPaymentSeries: "", booksPaymentNo: "",
      haewayaSeries: "", haewayaNo: "", externalPaymentSeries: "", externalPaymentNo: "" },
    search: ["Payment Series", "Haewaya Series"],
  },
};

const SETTINGS_ORDER = ["masterCategories", "itemCategories", "approvals", "tds", "taxes", "coa", "blockPaymentDate", "autoNumbers"];

const NAV = [
  ["Accounts", "calc"], ["Payments", "bank"], ["Bank", "bank2"], ["Bills", "bill"],
  ["Expenses", "exp"], ["Schedule Payments", "sched"], ["Expense Observations", "obs"],
  ["Masters", "mast"], ["Settings", "gear"], ["Backend Expenses", "exp"], ["Pending Approvals", "check"],
];

/* ══ shell ══════════════════════════════════════════════════════════════ */

export default function SettingsModule() {
  const [reportKey, setReportKey] = useState("masterCategories");
  const [flyout, setFlyout] = useState(false);
  const [flyTop, setFlyTop] = useState(0);
  const [data, setData] = useState(() =>
    Object.fromEntries(SETTINGS_ORDER.map((k) => [k, REPORTS[k].seed])));
  const [openId, setOpenId] = useState(null);
  const [editing, setEditing] = useState(null);
  const [showSearch, setShowSearch] = useState(false);
  const [blocked, setBlocked] = useState(false);
  const [toast, setToast] = useState("");
  const [search, setSearch] = useState({ field: "", value: "" });
  const [checked, setChecked] = useState(new Set());
  const [sort, setSort] = useState({ key: "", dir: "asc" });

  const cfg = REPORTS[reportKey];
  const rowsAll = data[reportKey] ?? [];
  const flyRef = useRef(null);

  useEffect(() => {
    const h = (e) => { if (flyRef.current && !flyRef.current.contains(e.target)) setFlyout(false); };
    document.addEventListener("mousedown", h); return () => document.removeEventListener("mousedown", h);
  }, []);

  const switchReport = (k) => {
    setReportKey(k); setFlyout(false); setOpenId(null); setEditing(null);
    setChecked(new Set()); setSort({ key: "", dir: "asc" });
    setSearch({ field: REPORTS[k].search[0] ?? "", value: "" });
    setBlocked(false); setToast("");
  };

  const cellText = (row, col) => {
    const v = col.derive ? col.derive(row) : row[col.k];
    if (col.type === T.bool) return bool(v);
    if (col.type === T.list) return (v ?? []).join(", ");
    if (col.type === T.pct) return v === "" || v === null || v === undefined ? ""
      : (col.dp ? (+v).toFixed(col.dp) : `${v}`);
    if (col.type === T.money) return money(v);
    if (col.type === T.date) return dmy(v);
    return v ?? "";
  };
  const cell = cellText;
  /* Multi-selects print one value per line in the live report, not comma-joined. */
  const cellNode = (row, col) => {
    if (col.type === T.list) {
      const list = (col.derive ? col.derive(row) : row[col.k]) ?? [];
      if (!cfg.stacked) return list.join(", ");
      return list.map((x, i) => <div key={i} className="zc-stackline">{x}</div>);
    }
    return cellText(row, col);
  };

  const rows = useMemo(() => {
    let r = rowsAll;
    if (search.value.trim() && search.field) {
      const q = search.value.toLowerCase();
      const col = cfg.columns.find((c) => c.label === search.field);
      if (col) r = r.filter((x) => String(cell(x, col)).toLowerCase().includes(q));
    }
    if (!sort.key) return r;
    const dir = sort.dir === "asc" ? 1 : -1;
    const col = cfg.columns.find((c) => c.k === sort.key);
    return [...r].sort((a, b) => {
      const av = a[sort.key], bv = b[sort.key];
      if (col?.type === T.num || col?.type === T.pct || col?.type === T.money) return ((+av || 0) - (+bv || 0)) * dir;
      return String(cell(a, col)).localeCompare(String(cell(b, col))) * dir;
    });
  }, [rowsAll, search, sort, cfg]);

  const save = (rec) => {
    setData((prev) => ({ ...prev,
      [reportKey]: prev[reportKey].some((x) => x.id === rec.id)
        ? prev[reportKey].map((x) => (x.id === rec.id ? rec : x))
        : [rec, ...prev[reportKey]] }));
    setEditing(null); setOpenId(rec.id);
    if (cfg.toast) { setToast(cfg.toast); setTimeout(() => setToast(""), 2600); }
  };
  const remove = (id) => {
    setData((prev) => ({ ...prev, [reportKey]: prev[reportKey].filter((x) => x.id !== id) }));
    setOpenId(null);
  };

  const openRec = openId ? rowsAll.find((x) => x.id === openId) : null;
  const openIdx = rows.findIndex((x) => x.id === openId);

  return (
    <>
      <Style />
      <div className="zc">
        <aside className="zc-rail">
          <div className="zc-logo">ACC</div>
          {NAV.map(([label, icon]) => {
            const isSettings = label === "Settings";
            return (
              <div key={label} className="zc-navwrap" ref={isSettings ? flyRef : null}>
                <button className={"zc-navitem" + (isSettings ? " on" : "")}
                  onClick={(e) => {
                    if (!isSettings) return;
                    setFlyTop(e.currentTarget.getBoundingClientRect().top);
                    setFlyout((f) => !f);
                  }}>
                  <Icon name={icon} /><span>{label}</span>
                </button>
                {isSettings && flyout && (
                  <div className="zc-flyout" style={{ top: flyTop }}>
                    {SETTINGS_ORDER.map((k) => (
                      <button key={k} className={"zc-flyitem" + (k === reportKey ? " on" : "")}
                        onClick={() => switchReport(k)}>
                        <Icon name="report" /><span>{REPORTS[k].nav}</span>
                      </button>
                    ))}
                  </div>
                )}
              </div>
            );
          })}
        </aside>

        <div className="zc-main">
          <header className="zc-appbar">
            <span className="zc-appname">Accounts</span>
            <div className="zc-appbar-r">
              <button className="zc-iconbtn" aria-label="Settings"><Icon name="gear" /></button>
              <button className="zc-iconbtn" aria-label="Notifications"><Icon name="bell" /></button>
              <span className="zc-user">Husain Khatumdi</span>
              <span className="zc-avatar"><Icon name="user" /></span>
            </div>
          </header>

          {/* No Save Changes / Remove Changes on these reports — they are not inline-editable */}
          <div className="zc-reportbar">
            <h1>{cfg.title}{cfg.star && <i className="zc-req">*</i>}</h1>
            {cfg.savable && <>
              <button className="zc-btn zc-btn-out">Save Changes</button>
              <button className="zc-btn zc-btn-out">Remove Changes</button>
            </>}
            <div className="zc-reportbar-r">
              <button className="zc-iconbtn zc-sq" onClick={() => setShowSearch((s) => !s)} aria-label="Search"><Icon name="search" /></button>
              <button className="zc-add" aria-label="Add"
                onClick={() => (cfg.singleton && rowsAll.length > 0 ? setBlocked(true) : setEditing("new"))}>＋</button>
              <button className="zc-iconbtn zc-sq" aria-label="More">···</button>
            </div>
          </div>

          {showSearch && !cfg.unspecified && (
            <div className="zc-searchrow">
              <span className="zc-searchlabel">SEARCH</span>
              <div className="zc-searchchip">
                <select value={search.field || cfg.search[0]} onChange={(e) => setSearch((s) => ({ ...s, field: e.target.value }))}>
                  {cfg.search.map((f) => <option key={f}>{f}</option>)}
                </select>
                <span className="zc-op">contains</span>
                <input value={search.value} onChange={(e) => setSearch((s) => ({ ...s, value: e.target.value }))} placeholder="…" />
                {search.value && <button onClick={() => setSearch((s) => ({ ...s, value: "" }))} aria-label="Clear">✕</button>}
              </div>
            </div>
          )}

          {(
            <>
              <div className="zc-gridwrap">
                <table className={"zc-grid" + (cfg.stacked ? " zc-grid-tall" : "")}>
                  <thead>
                    <tr>
                      <th className="zc-c-eye"><Icon name="eye" /></th>
                      <th className="zc-c-chk">
                        <input type="checkbox" checked={checked.size === rows.length && rows.length > 0}
                          onChange={(e) => setChecked(e.target.checked ? new Set(rows.map((r) => r.id)) : new Set())}
                          aria-label="Select all" />
                      </th>
                      {cfg.columns.map((col) => (
                        <Th key={col.k} k={col.k} s={sort} set={setSort} w={col.w}
                          num={col.type === T.num || col.type === T.pct || col.type === T.money}>{col.label}</Th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {rows.map((row) => (
                      <tr key={row.id} className={openId === row.id ? "sel" : ""} onClick={() => setOpenId(row.id)}>
                        <td className="zc-c-eye">{openId === row.id ? <span className="zc-dots">···</span> : null}</td>
                        <td className="zc-c-chk" onClick={(e) => e.stopPropagation()}>
                          <input type="checkbox" checked={checked.has(row.id)} aria-label="Select row"
                            onChange={() => setChecked((prev) => { const n = new Set(prev); n.has(row.id) ? n.delete(row.id) : n.add(row.id); return n; })} />
                        </td>
                        {cfg.columns.map((col) => {
                          const mono = col.type === T.id || col.type === T.num || col.type === T.pct || col.type === T.money;
                          const txt = cellText(row, col);
                          return (
                            <td key={col.k} title={cfg.stacked ? "" : String(txt)}
                              className={(mono ? "mono " : "") + (col.type === T.id ? "zc-id " : "")
                                + (col.type === T.num || col.type === T.pct || col.type === T.money ? "num " : "")}>
                              {cellNode(row, col)}
                            </td>
                          );
                        })}
                      </tr>
                    ))}
                    {rows.length === 0 && (
                      <tr><td colSpan={cfg.columns.length + 2} className="zc-empty">No records found</td></tr>
                    )}
                  </tbody>
                </table>
              </div>

              <footer className="zc-footer">
                <span>Showing {rows.length} of {rowsAll.length}</span>
                {checked.size > 0 && <span className="zc-selcount">{checked.size} selected</span>}
                {!cfg.verified && (
                  <span className="zc-unver">column order and form field order not screenshot-verified</span>
                )}
              </footer>
            </>
          )}
        </div>

        {openRec && (
          <DetailPanel cfg={cfg} rec={openRec} onClose={() => setOpenId(null)}
            onEdit={() => setEditing(openRec)} onDelete={() => remove(openRec.id)}
            onPrev={openIdx > 0 ? () => setOpenId(rows[openIdx - 1].id) : null}
            onNext={openIdx < rows.length - 1 ? () => setOpenId(rows[openIdx + 1].id) : null} />
        )}
        {toast && (
          <div className="zc-toast"><Icon name="tick" />{toast}</div>
        )}
        {blocked && (
          <div className="zc-modalback">
            <div className="zc-dialog">
              <svg width="96" height="76" viewBox="0 0 96 76" fill="none" stroke="#8b90ad" strokeWidth="1.4" aria-hidden="true">
                <circle cx="14" cy="12" r="3" />
                <rect x="22" y="8" width="52" height="52" rx="4" />
                <path d="M30 18h8M30 26h14M30 34h8M30 42h12" />
                <circle cx="66" cy="46" r="14" fill="#fff" />
                <path d="M62 42a4 4 0 118 0c0 3-4 3-4 6M66 54v.5" />
              </svg>
              <p>{cfg.singletonMessage}</p>
              <div className="zc-dialogfoot">
                <button className="zc-btn zc-btn-pri" onClick={() => setBlocked(false)}>OK</button>
              </div>
            </div>
          </div>
        )}
        {editing && (
          <RecordForm cfg={cfg} initial={editing === "new" ? null : editing}
            onCancel={() => setEditing(null)} onSave={save} />
        )}
      </div>
    </>
  );
}

function Th({ children, k, s, set, w, num }) {
  const on = s.key === k;
  return (
    <th style={{ width: w }} className={num ? "num" : ""}>
      <button className="zc-th" onClick={(e) => { e.stopPropagation(); set({ key: k, dir: on && s.dir === "asc" ? "desc" : "asc" }); }}>
        <span>{children}</span><i className={"zc-caret" + (on ? " on " + s.dir : "")} />
      </button>
    </th>
  );
}

/* ══ detail panel ═══════════════════════════════════════════════════════
   Bar carries ‹ › then Edit / Delete / More ⌄ / ✕ — no record title, no
   status chip. Body is a bare 50/50 key-value table, no section heading. */

function DetailPanel({ cfg, rec, onClose, onEdit, onDelete, onPrev, onNext }) {
  const [menu, setMenu] = useState(false);
  const label = (k) => cfg.columns.find((c) => c.k === k)?.label
    ?? cfg.form.find((f) => f.k === k)?.label ?? k;
  const render = (k) => {
    const col = cfg.columns.find((c) => c.k === k);
    const v = col?.derive ? col.derive(rec) : rec[k];
    if (Array.isArray(v)) return v.map((x, i) => <div key={i} className="zc-stackline">{String(x)}</div>);
    if (typeof v === "boolean") return bool(v);
    if (col?.type === T.id) return <span className="mono zc-id">{v}</span>;
    if (col?.type === T.date) return dmy(v);
    if (col?.type === T.num || col?.type === T.pct) return <span className="mono">{v}</span>;
    return v ?? "";
  };
  useEffect(() => {
    const h = (e) => { if (e.key === "Escape") onClose(); };
    window.addEventListener("keydown", h); return () => window.removeEventListener("keydown", h);
  }, [onClose]);

  return (
    <aside className="zc-panel">
      <div className="zc-panelbar">
        <div className="zc-nav2">
          <button className="zc-iconbtn zc-sq" onClick={onPrev} disabled={!onPrev} aria-label="Previous">‹</button>
          <button className="zc-iconbtn zc-sq" onClick={onNext} disabled={!onNext} aria-label="Next">›</button>
        </div>
        <div className="zc-panelacts">
          <button className="zc-btn zc-btn-edit" onClick={onEdit}><Icon name="pencil" />Edit</button>
          <button className="zc-btn zc-btn-out" onClick={onDelete}><Icon name="trash" />Delete</button>
          <div className="zc-menuwrap">
            <button className="zc-btn zc-btn-out" onClick={() => setMenu((m) => !m)}>More ⌄</button>
            {menu && (
              <div className="zc-menu">
                <button onClick={() => setMenu(false)}>Duplicate</button>
                <button onClick={() => setMenu(false)}>Print</button>
              </div>
            )}
          </div>
          <button className="zc-iconbtn zc-sq" onClick={onClose} aria-label="Close">✕</button>
        </div>
      </div>

      <div className="zc-panelbody">
        <table className="zc-kv"><tbody>
          {cfg.detail.map((k) => <tr key={k}><th>{label(k)}</th><td>{render(k)}</td></tr>)}
        </tbody></table>

        <p className="zc-addcomment"><Icon name="comment" />Add a comment</p>
      </div>
    </aside>
  );
}

/* ══ form ═══════════════════════════════════════════════════════════════
   A near-full-screen overlay, not a centred modal. Single column, labels
   left. Checkboxes sit above the text fields, as the screenshot shows. */

function RecordForm({ cfg, initial, onCancel, onSave }) {
  const [rec, setRec] = useState(() => initial ?? { id: rid(Math.floor(900000 + Math.random() * 90000)), ...structuredClone(cfg.blank) });
  const set = (k, v) => setRec((p) => ({ ...p, [k]: v }));
  const isEdit = !!initial;

  const subCols = cfg.subformCols ?? [];
  const addSubRow = () => setRec((p) => ({ ...p,
    [cfg.subform]: [...(p[cfg.subform] ?? []), { id: uid(), ...structuredClone(cfg.subformBlank ?? {}) }] }));
  const setSubRow = (id, k, v) => setRec((p) => ({ ...p,
    [cfg.subform]: p[cfg.subform].map((r) => (r.id === id ? { ...r, [k]: v } : r)) }));
  const dropSubRow = (id) => setRec((p) => ({ ...p, [cfg.subform]: p[cfg.subform].filter((r) => r.id !== id) }));

  const field = (f, val, onSet) => {
    if (f.type === "lookup" || f.type === "select") {
      return (
        <div className="zc-lookup">
          <select className={"zc-in" + (val ? "" : " ph")} value={val ?? ""} onChange={(e) => onSet(e.target.value)}>
            <option value="">-Select-</option>
            {(f.options ?? []).map((o) => <option key={o}>{o}</option>)}
          </select>
          {val && <button className="zc-clear" onClick={() => onSet("")} aria-label="Clear">✕</button>}
        </div>
      );
    }
    if (f.type === "multi") return <ChipBox options={f.options} value={val ?? []} onChange={onSet} scroll={f.scroll} />;
    if (f.type === "radio") {
      return (
        <div className="zc-radios">
          {f.options.map((o) => (
            <label key={o} className="zc-radio">
              <input type="radio" checked={val === o} onChange={() => onSet(o)} />
              <span>{o}</span>
            </label>
          ))}
        </div>
      );
    }
    if (f.type === "rupee" || f.suffix) {
      return (
        <div className="zc-suffixed">
          <input className="zc-in mono" value={f.type === "rupee" ? inr(val) : (val ?? "")}
            onChange={(e) => onSet(f.type === "rupee" ? e.target.value.replace(/,/g, "") : e.target.value)} />
          <span className="zc-suffix">{f.type === "rupee" ? "₹" : f.suffix}</span>
        </div>
      );
    }
    if (f.type === T.date) return <DateBox value={val} onChange={onSet} />;
    return <input className={"zc-in" + (f.type === T.num ? " mono" : "")} value={val ?? ""}
      onChange={(e) => onSet(e.target.value)} />;
  };

  const rowFor = (f) => {
    if (f.section) return <div className="zc-formsection" key={"s-" + f.section}>{f.section}</div>;
    if (f.type === T.bool) {
      return (
        <label key={f.k} className="zc-formcheck">
          <input type="checkbox" checked={!!rec[f.k]} onChange={(e) => set(f.k, e.target.checked)} />
          <span>{f.label}</span>
        </label>
      );
    }
    return (
      <div className="zc-formfield" key={f.k}>
        <div className={"zc-formrow" + (f.type === "multi" || f.type === "radio" ? " top" : "")}>
          <label>{f.label}</label>
          {field(f, rec[f.k], (v) => set(f.k, v))}
        </div>
        {f.hint && <p className="zc-fhint">{f.hint}</p>}
      </div>
    );
  };

  return (
    <div className="zc-formpage">
      <header className="zc-appbar">
        <span className="zc-appname">Accounts</span>
        <div className="zc-appbar-r">
          <button className="zc-iconbtn" aria-label="Settings"><Icon name="gear" /></button>
          <button className="zc-iconbtn" aria-label="Notifications"><Icon name="bell" /></button>
          <span className="zc-user">Husain Khatumdi</span>
          <span className="zc-avatar"><Icon name="user" /></span>
        </div>
      </header>
      <div className="zc-formtitle">{cfg.formTitle}</div>

      <div className="zc-formscroll">
      <div className="zc-formbody">
        {/* Field order is the config order. Checkboxes sit inline where they are
            declared rather than being grouped — see the Item Category form. */}
        {cfg.twoCol ? (
          <div className="zc-form2col">
            <div>{cfg.form.map(rowFor)}</div>
            <div>{(cfg.formRight ?? []).map(rowFor)}</div>
          </div>
        ) : cfg.form.map(rowFor)}

        {cfg.subform && subCols.length > 0 && (
          <>
            <div className="zc-formsub">{cfg.subformTitle ?? "Rows"}</div>
            <div className="zc-subwrap">
              <table className="zc-subedit">
                <thead>
                  <tr>
                    {subCols.map((c) => <th key={c.k} style={{ width: c.w }}>{c.label}</th>)}
                    <th style={{ width: 30 }} />
                  </tr>
                </thead>
                <tbody>
                  {(rec[cfg.subform] ?? []).map((r) => (
                    <tr key={r.id}>
                      {subCols.map((c) => (
                        <td key={c.k}>{field(c, r[c.k], (v) => setSubRow(r.id, c.k, v))}</td>
                      ))}
                      <td><button className="zc-x" onClick={() => dropSubRow(r.id)} aria-label="Remove row">✕</button></td>
                    </tr>
                  ))}
                  {(rec[cfg.subform] ?? []).length === 0 && (
                    <tr><td colSpan={subCols.length + 1} className="zc-empty">No rows</td></tr>
                  )}
                </tbody>
              </table>
            </div>
            <button className="zc-addnew" onClick={addSubRow}>+ {cfg.subformAdd ?? "Add New"}</button>
          </>
        )}
      </div>

      <div className="zc-formfoot">
        <button className="zc-btn zc-btn-pri" onClick={() => onSave(rec)}>{isEdit ? "Update" : "Submit"}</button>
        <button className="zc-btn zc-btn-out" onClick={onCancel}>Cancel</button>
      </div>
      </div>
    </div>
  );
}

/* dd-MMM-yyyy text field, as Creator uses — a native date input renders mm/dd/yyyy. */
function DateBox({ value, onChange }) {
  const [txt, setTxt] = useState(dmy(value));
  useEffect(() => setTxt(dmy(value)), [value]);
  return (
    <div className="zc-datebox">
      <input className="zc-in" value={txt} placeholder="dd-MMM-yyyy"
        onChange={(e) => setTxt(e.target.value)}
        onBlur={() => { const iso = parseDmy(txt); onChange(iso); setTxt(iso ? dmy(iso) : ""); }} />
      <span className="zc-cal">▤</span>
    </div>
  );
}

/* Multi-select: chips one per line when long, several per line when short, with a
   leading ✕ on each chip as Creator renders them. */
function ChipBox({ options = [], value = [], onChange, scroll }) {
  const [q, setQ] = useState("");
  const [open, setOpen] = useState(false);
  const ref = useRef(null);
  useEffect(() => {
    const h = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    document.addEventListener("mousedown", h); return () => document.removeEventListener("mousedown", h);
  }, []);
  const avail = options.filter((o) => !value.includes(o) && o.toLowerCase().includes(q.toLowerCase()));
  return (
    <div className="zc-chipwrap" ref={ref}>
      <div className={"zc-chipbox" + (scroll ? " scroll" : "")} onClick={() => setOpen(true)}>
        {value.map((v) => (
          <span className="zc-chip" key={v}>
            <button onClick={(e) => { e.stopPropagation(); onChange(value.filter((x) => x !== v)); }} aria-label="Remove">✕</button>
            {v}
          </span>
        ))}
        <input value={q} onChange={(e) => { setQ(e.target.value); setOpen(true); }}
          onFocus={() => setOpen(true)} placeholder={value.length ? "" : "-Select-"} />
      </div>
      {open && avail.length > 0 && (
        <ul className="zc-droplist">
          {avail.slice(0, 12).map((o) => (
            <li key={o}><button onClick={() => { onChange([...value, o]); setQ(""); }}>{o}</button></li>
          ))}
        </ul>
      )}
    </div>
  );
}

/* ══ icons and style ════════════════════════════════════════════════════ */

function Icon({ name }) {
  const a = { width: 16, height: 16, viewBox: "0 0 24 24", fill: "none", stroke: "currentColor", strokeWidth: 1.7, strokeLinecap: "round", strokeLinejoin: "round" };
  const s = {
    calc: <><rect x="4" y="2" width="16" height="20" rx="2" /><path d="M8 6h8M8 11h2M12 11h2M8 15h2M12 15h2M16 15v3" /></>,
    bank: <><path d="M3 21h18M5 21V9l7-5 7 5v12M9 21v-6h6v6" /></>,
    bank2: <><path d="M3 10l9-6 9 6M4 10v11h16V10M9 21v-7h6v7" /></>,
    bill: <><path d="M6 2h12v20l-3-2-3 2-3-2-3 2V2z" /><path d="M9 7h6M9 11h6M9 15h3" /></>,
    exp: <><circle cx="12" cy="12" r="9" /><path d="M12 7v10M9.5 9.5h5M9.5 14.5h5" /></>,
    sched: <><rect x="3" y="5" width="18" height="16" rx="2" /><path d="M8 3v4M16 3v4M3 11h18" /></>,
    obs: <><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6z" /><circle cx="12" cy="12" r="2.5" /></>,
    mast: <><ellipse cx="12" cy="6" rx="8" ry="3" /><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6" /></>,
    check: <><path d="M20 6L9 17l-5-5" /></>,
    tick: <><path d="M20 6L9 17l-5-5" /></>,
    gear: <><circle cx="12" cy="12" r="3" /><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1" /></>,
    bell: <><path d="M18 8a6 6 0 10-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" /><path d="M10.3 21a2 2 0 003.4 0" /></>,
    user: <><circle cx="12" cy="8" r="4" /><path d="M4 21c0-4 3.6-6 8-6s8 2 8 6" /></>,
    search: <><circle cx="11" cy="11" r="7" /><path d="M20 20l-4.3-4.3" /></>,
    eye: <><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6z" /><circle cx="12" cy="12" r="2.5" /></>,
    report: <><rect x="2" y="4" width="20" height="14" rx="2" /><path d="M8 21h8M12 18v3" /></>,
    pencil: <><path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4z" /></>,
    trash: <><path d="M3 6h18M8 6V4h8v2M5 6l1 14h12l1-14" /></>,
    comment: <><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z" /></>,
  };
  return <svg {...a} aria-hidden="true">{s[name]}</svg>;
}

function Style() {
  return (
    <style>{`
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Roboto+Mono:wght@400;500&display=swap');
*,*::before,*::after{box-sizing:border-box}
.zc{
  --rail:#2b2f4a; --rail2:#383d5e; --pink:#e4407f; --pinkd:#c72e69; --pinkl:#fdeef4;
  --ink:#20242e; --ink2:#4a5160; --ink3:#7b8494; --ink4:#a8afbb;
  --line:#e6e9ee; --line2:#d2d7df; --bg:#f4f5f7; --white:#fff;
  --ok:#0f7b5f; --okbg:#e9f6f1; --bad:#c0392b; --badbg:#fdeceb;
  --warn:#9a6206; --warnbg:#fdf3e2; --info:#2b5fa8; --infobg:#eaf1fb;
  --sans:'Inter',-apple-system,'Segoe UI',Roboto,sans-serif; --mono:'Roboto Mono',ui-monospace,monospace;
  font-family:var(--sans); color:var(--ink); background:var(--bg);
  display:grid; grid-template-columns:104px minmax(0,1fr); height:100vh; overflow:hidden;
  font-size:13px; -webkit-font-smoothing:antialiased;
}
.zc :focus-visible{outline:2px solid var(--pink); outline-offset:1px}
.mono{font-family:var(--mono); font-variant-numeric:tabular-nums; font-size:12px; letter-spacing:-.2px}
.num{text-align:right} .nowrap{white-space:nowrap} .strong{font-weight:600}

.zc-rail{background:var(--rail); display:flex; flex-direction:column; overflow:visible}
.zc-logo{background:var(--pink); color:#fff; font-weight:700; font-size:13px; letter-spacing:.1em; height:46px; display:grid; place-items:center; flex:none}
.zc-navwrap{position:relative; flex:none}
.zc-navitem{width:100%; background:none; border:0; color:#bcc2d2; font:inherit; font-size:10px; line-height:1.3; padding:10px 5px 8px;
  display:grid; justify-items:center; gap:5px; cursor:pointer; text-align:center; word-break:break-word}
.zc-navitem:hover{background:var(--rail2); color:#fff}
.zc-navitem.on{background:var(--pink); color:#fff}
.zc-flyout{position:fixed; left:104px; z-index:40; min-width:238px; background:var(--rail2);
  box-shadow:6px 6px 22px rgba(20,22,34,.34); padding:8px 0}
.zc-flyitem{width:100%; background:none; border:0; color:#e4e7f0; font:inherit; font-size:13.5px; text-align:left;
  padding:9px 18px; display:flex; align-items:center; gap:11px; cursor:pointer; white-space:nowrap}
.zc-flyitem:hover{background:#464c72; color:#fff}
.zc-flyitem.on{color:#fff; font-weight:500}
.zc-flyitem svg{opacity:.72; flex:none}

.zc-main{display:flex; flex-direction:column; min-width:0; min-height:0; background:var(--white)}
.zc-appbar{height:42px; flex:none; display:flex; align-items:center; justify-content:space-between; padding:0 14px; border-bottom:1px solid var(--line); background:var(--white)}
.zc-appname{font-size:15px; font-weight:500}
.zc-appbar-r{display:flex; align-items:center; gap:9px; color:var(--ink3)}
.zc-user{font-size:12.5px; color:var(--ink2)}
.zc-avatar{width:25px; height:25px; border-radius:50%; background:var(--line); color:var(--ink3); display:grid; place-items:center}
.zc-iconbtn{background:none; border:0; color:var(--ink3); cursor:pointer; padding:3px; display:grid; place-items:center; border-radius:3px; font:inherit}
.zc-iconbtn:hover:not(:disabled){color:var(--ink); background:var(--bg)}
.zc-iconbtn:disabled{opacity:.3; cursor:not-allowed}
.zc-sq{width:25px; height:25px; border:1px solid var(--line2); font-size:12px; line-height:1}

.zc-reportbar{flex:none; display:flex; align-items:center; gap:8px; padding:8px 14px; border-bottom:1px solid var(--line)}
.zc-reportbar h1{margin:0 4px 0 0; font-size:16px; font-weight:500}
.zc-reportbar-r{margin-left:auto; display:flex; align-items:center; gap:6px}
.zc-add{width:27px; height:27px; border:0; border-radius:3px; background:var(--pink); color:#fff; font-size:15px; line-height:1; cursor:pointer}
.zc-add:hover:not(:disabled){background:var(--pinkd)}
.zc-add:disabled{opacity:.4; cursor:not-allowed}
.zc-btn{font:inherit; font-size:12.5px; height:27px; padding:0 10px; border-radius:3px; cursor:pointer; white-space:nowrap;
  display:inline-flex; align-items:center; gap:5px}
.zc-btn svg{width:13px; height:13px}
.zc-btn:disabled{opacity:.4; cursor:not-allowed}
.zc-btn-out{background:var(--white); border:1px solid var(--line2); color:var(--ink2)}
.zc-btn-out:hover:not(:disabled){border-color:var(--ink4); color:var(--ink)}
.zc-btn-edit{background:var(--pinkl); border:1px solid var(--pink); color:var(--pink); font-weight:500}
.zc-btn-edit:hover{background:var(--pink); color:#fff}
.zc-btn-pri{background:var(--pink); border:1px solid var(--pink); color:#fff; font-weight:500}
.zc-btn-pri:hover:not(:disabled){background:var(--pinkd)}

.zc-searchrow{flex:none; display:flex; align-items:center; padding:6px 14px; border-bottom:1px solid var(--line); background:var(--bg)}
.zc-searchlabel{font-size:10px; font-weight:600; letter-spacing:.06em; color:var(--ink3); border:1px solid var(--line2);
  border-right:0; background:var(--white); padding:5px 8px; border-radius:3px 0 0 3px}
.zc-searchchip{display:flex; align-items:center; gap:5px; border:1px solid var(--pink); border-radius:0 3px 3px 0; background:var(--white); padding:2px 6px 2px 4px}
.zc-searchchip select,.zc-searchchip input{border:0; outline:0; font:inherit; font-size:12.5px; background:none; color:var(--ink)}
.zc-searchchip input{width:140px}
.zc-op{font-size:12px; color:var(--ink3)}
.zc-searchchip button{border:0; background:none; color:var(--pink); cursor:pointer; font-size:10px; padding:0 2px}

.zc-gridwrap{flex:1; overflow:auto; min-height:0}
.zc-grid{border-collapse:separate; border-spacing:0; font-size:12.5px; width:max-content; min-width:100%}
.zc-grid thead th.num{text-align:right}
.zc-grid thead th.num .zc-th{justify-content:flex-end}
.zc-grid thead th{position:sticky; top:0; z-index:2; background:var(--white); text-align:left; font-weight:600; font-size:11.5px;
  color:var(--ink); padding:0; height:31px; border-bottom:1px solid var(--line2); border-right:1px solid var(--line); white-space:nowrap}
.zc-th{width:100%; height:31px; display:flex; align-items:center; gap:4px; justify-content:space-between; font:inherit;
  font-weight:600; font-size:11.5px; color:inherit; background:none; border:0; cursor:pointer; padding:0 7px}
.zc-caret{width:0; height:0; border-left:3.5px solid transparent; border-right:3.5px solid transparent;
  border-top:4.5px solid var(--ink4); opacity:.5; flex:none}
.zc-caret.on{opacity:1; border-top-color:var(--pink)}
.zc-caret.on.asc{border-top:0; border-bottom:4.5px solid var(--pink)}
.zc-grid tbody td{padding:0 7px; border-bottom:1px solid var(--line); border-right:1px solid var(--line);
  height:27px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:0}
.zc-grid-tall tbody td{height:auto; white-space:normal; overflow:visible; text-overflow:clip; max-width:none;
  vertical-align:top; padding:7px}
.zc-grid tbody tr{cursor:pointer}
.zc-grid tbody tr:hover td{background:var(--pinkl)}
.zc-grid tbody tr.sel td{background:var(--pinkl); box-shadow:inset 0 -1px 0 var(--pink)}
.zc-c-eye,.zc-c-chk{width:28px; text-align:center; color:var(--ink4); padding:0 !important}
.zc-c-chk input{accent-color:var(--pink); margin:0}
.zc-dots{color:var(--pink); font-weight:700; letter-spacing:1px}
.zc-id{color:var(--ink); font-size:11.5px}
.zc-empty{color:var(--ink3); text-align:center; padding:14px !important; font-size:12px; max-width:none !important}
.zc-footer{flex:none; display:flex; align-items:center; gap:14px; height:28px; padding:0 14px;
  border-top:1px solid var(--line2); background:var(--bg); font-size:12px; color:var(--ink2)}
.zc-selcount{color:var(--pink); font-weight:500}
.zc-unver{margin-left:auto; color:var(--warn); font-size:11.5px}

.zc-modalback{position:fixed; inset:0; background:rgba(32,36,46,.35); z-index:70; display:grid; place-items:start center; padding:18px}
.zc-toast{position:fixed; top:9px; left:50%; transform:translateX(-50%); z-index:80; background:var(--pink); color:#fff;
  display:flex; align-items:center; gap:9px; padding:11px 22px; border-radius:3px; font-size:14px; font-weight:500;
  box-shadow:0 4px 14px rgba(32,36,46,.2)}
.zc-dialog{background:var(--white); width:min(530px,100%); margin-top:16vh; border-radius:4px; text-align:center;
  box-shadow:0 18px 50px rgba(32,36,46,.28); padding:34px 30px 0}
.zc-dialog svg{margin:0 auto 22px; display:block}
.zc-dialog p{margin:0 0 26px; font-size:14.5px; color:var(--ink)}
.zc-dialogfoot{border-top:1px solid var(--line); margin:0 -30px; padding:18px 0 22px}
.zc-dialogfoot .zc-btn{height:34px; padding:0 26px; font-size:14px}
.zc-datebox{position:relative}
.zc-cal{position:absolute; right:10px; top:8px; color:var(--ink4); font-size:12px; pointer-events:none}
.zc-unspec{flex:1; overflow-y:auto; padding:26px 30px; max-width:760px}
.zc-unspec h2{margin:0 0 12px; font-size:16px; font-weight:600}
.zc-unspec p{margin:0 0 11px; font-size:13px; line-height:1.6; color:var(--ink2)}
.zc-unspec-q{border-left:3px solid var(--pink); background:var(--pinkl); padding:9px 12px}

.zc-panel{position:fixed; top:0; right:0; bottom:0; width:min(900px,62vw); background:var(--white);
  border-left:1px solid var(--line2); box-shadow:-8px 0 26px rgba(32,36,46,.10); display:flex; flex-direction:column; z-index:30}
.zc-panelbar{flex:none; display:flex; align-items:center; justify-content:space-between; gap:10px; padding:9px 14px; border-bottom:1px solid var(--line)}
.zc-nav2,.zc-panelacts{display:flex; align-items:center; gap:7px}
.zc-menuwrap{position:relative}
.zc-menu{position:absolute; right:0; top:31px; background:var(--white); border:1px solid var(--line2); border-radius:3px;
  box-shadow:0 6px 18px rgba(32,36,46,.14); padding:3px; min-width:130px; z-index:5}
.zc-menu button{width:100%; text-align:left; font:inherit; font-size:12.5px; padding:6px 9px; border:0; background:none; cursor:pointer; color:var(--ink2)}
.zc-menu button:hover{background:var(--bg)}
.zc-panelbody{overflow-y:auto; padding:18px 20px 28px}
.zc-kv{width:100%; border-collapse:collapse; font-size:13px; table-layout:fixed}
.zc-kv th{width:50%; text-align:left; vertical-align:top; font-weight:400; color:var(--ink);
  background:#fafbfc; padding:9px 14px; border:1px solid var(--line)}
.zc-kv td{padding:9px 14px; border:1px solid var(--line); word-break:break-word; vertical-align:top}
.zc-sub{width:100%; border-collapse:collapse; font-size:12px}
.zc-sub th{text-align:left; font-weight:600; color:var(--ink2); background:#fafbfc; padding:5px 8px; border:1px solid var(--line); white-space:nowrap}
.zc-sub th.num{text-align:right}
.zc-sub td{padding:5px 8px; border:1px solid var(--line)}
.zc-sub td.num{white-space:nowrap}
.zc-addcomment{margin:22px 0 0; color:var(--pink); font-size:13px; cursor:pointer; display:flex; align-items:center; gap:7px}

.zc-formpage{position:fixed; top:0; right:0; bottom:0; left:30px; background:var(--white); z-index:60; display:flex; flex-direction:column}
.zc-formtitle{flex:none; padding:16px 30px; font-size:17px; font-weight:500; background:#fafbfc; border-bottom:1px solid var(--line)}
.zc-formscroll{flex:1; overflow-y:auto}
.zc-formbody{padding:22px 30px 20px}
.zc-formcheck{display:flex; align-items:center; gap:9px; font-size:13px; cursor:pointer; margin-bottom:14px}
.zc-formcheck input{appearance:none; -webkit-appearance:none; width:16px; height:16px; margin:0;
  border:1.5px solid var(--pink); border-radius:3px; background:var(--white); cursor:pointer; position:relative}
.zc-formcheck input:checked{background:var(--pink)}
.zc-formcheck input:checked::after{content:''; position:absolute; left:4px; top:1px; width:5px; height:9px;
  border:solid #fff; border-width:0 1.6px 1.6px 0; transform:rotate(42deg)}
.zc-form2col{display:grid; grid-template-columns:600px minmax(0,1fr); gap:0 40px; align-items:start}
.zc-formfield{margin-bottom:2px}
.zc-formrow.top{align-items:start}
.zc-formrow.top > label{padding-top:7px}
.zc-radios{display:flex; gap:22px; align-items:center; height:32px}
.zc-radio{display:inline-flex; align-items:center; gap:7px; font-size:13px; cursor:pointer}
.zc-radio input{accent-color:var(--pink); width:14px; height:14px; margin:0}
.zc-chipwrap{position:relative}
.zc-chipbox{display:flex; flex-wrap:wrap; gap:5px; align-content:flex-start; min-height:78px; padding:7px 8px;
  border:1px solid var(--line2); border-radius:3px; background:var(--white); cursor:text}
.zc-chipbox.scroll{max-height:460px; overflow-y:auto}
.zc-chipbox input{border:0; outline:0; font:inherit; font-size:13px; background:none; flex:1; min-width:70px; height:22px; padding:0}
.zc-chipbox input::placeholder{color:var(--ink4)}
.zc-chip{display:inline-flex; align-items:flex-start; gap:5px; font-size:12.5px; background:var(--white);
  border:1px solid var(--line2); border-radius:3px; padding:3px 7px 3px 5px; color:var(--ink2); line-height:1.35; max-width:100%}
.zc-chip button{border:0; background:none; color:var(--ink4); cursor:pointer; font-size:10px; padding:1px 0 0; flex:none}
.zc-chip button:hover{color:var(--bad)}
.zc-subwrap{overflow-x:auto}
.zc-stackline{line-height:1.5}
.zc-formrow{display:grid; grid-template-columns:190px 340px; align-items:center; gap:14px; padding:6px 0}
.zc-fhint{margin:0 0 8px 204px; font-size:12px; color:var(--ink3); max-width:420px}
.zc-lookup{position:relative}
.zc-lookup .zc-in{padding-right:46px; appearance:none; -webkit-appearance:none;
  background-image:linear-gradient(45deg,transparent 50%,var(--ink3) 50%),linear-gradient(135deg,var(--ink3) 50%,transparent 50%);
  background-position:calc(100% - 15px) 14px,calc(100% - 10px) 14px; background-size:5px 5px,5px 5px; background-repeat:no-repeat}
.zc-lookup .zc-in.ph{color:var(--ink4)}
.zc-clear{position:absolute; right:26px; top:8px; border:0; background:none; color:var(--ink3); cursor:pointer; font-size:11px; padding:0 2px}
.zc-clear:hover{color:var(--bad)}
.zc-suffixed{display:flex}
.zc-suffixed .zc-in{border-radius:3px 0 0 3px}
.zc-subedit .zc-suffixed .zc-in{border-radius:3px 0 0 3px}
.zc-suffix{width:40px; display:grid; place-items:center; font-size:13px; color:var(--ink2);
  border:1px solid var(--line2); border-left:0; border-radius:0 3px 3px 0; background:#fafbfc}
.zc-formrow > label{font-size:13px; color:var(--ink2)}
.zc-formsub{margin:22px 0 8px; font-size:13px; font-weight:600}
.zc-formsection{margin:26px 0 16px; font-size:17px; font-weight:500; color:var(--ink);
  padding-bottom:12px; border-bottom:1px solid var(--line)}
.zc-formsection:first-child{margin-top:4px}
.zc-formfoot{display:flex; gap:10px; padding:16px 30px 24px; border-top:1px solid var(--line)}
.zc-in{font:inherit; font-size:13px; height:32px; padding:0 8px; border:1px solid var(--line2); border-radius:3px;
  background:var(--white); color:var(--ink); width:100%}
.zc-in.num{text-align:right}
.zc-in:focus{border-color:var(--pink); outline:0}
.zc-subedit{width:100%; border-collapse:collapse; font-size:12px}
.zc-subedit th{text-align:left; font-weight:600; color:var(--ink2); background:#fafbfc; padding:5px 7px;
  border:1px solid var(--line); white-space:nowrap; font-size:11.5px}
.zc-subedit th.num{text-align:right}
.zc-subedit td{padding:2px 4px; border:1px solid var(--line); vertical-align:middle}
.zc-subedit .zc-in{height:26px; font-size:12px}
.zc-x{border:0; background:none; color:var(--ink4); cursor:pointer; font-size:10px; padding:3px 5px; border-radius:2px}
.zc-x:hover{color:var(--bad); background:var(--badbg)}
.zc-addnew{margin-top:7px; font:inherit; font-size:12.5px; color:var(--pink); background:none; border:0; cursor:pointer; padding:3px 2px}
@media (max-width:820px){ .zc{grid-template-columns:1fr; height:auto} .zc-rail{flex-direction:row; overflow-x:auto} .zc-panel{width:100vw} }
`}</style>
  );
}
