# Accounts app — form schema

Extracted from the 12-Aug-2026 19:39:44 export paste. **See `SOURCE_PROVENANCE.md`** —
this is transcribed, not exported. Verify literals before implementing.

46 forms. Listed below in export order. `→` = lookup by record ID.
`must have` = Creator's mandatory marker.

---

## Core transactional forms

### Bills

Mandatory: `Bill_Date`, `Vendor_Name`, `Item_Category`, `COA`, `Billing_Cycles`,
`Bill_No`, `Villas`, `Total_Amount`.

```
Bill_Date        date
Vendor_Name      → Vendor_Master  displayformat [Vendor_Name]
Due_Date         date
Item_Category    → Item_Category[ Item_Category != "PETTY"
                                && Item_Category != "INTERNAL TRANSFER"
                                && Master_Category.ID != 292482000000124003 ]  LIST
Master_Category  → Master_Category  LIST      (derived from Item_Category)
COA              → COA                        (unfiltered — cf. Payment)
CA_Email         email  maxchar 80  personal data
GST_Needed       checkbox
Billing_Year     number
Billing_Months   list of {January..December}
Billing_Cycles   → Billing_Cycles  LIST  displayformat [Month_field + " - " + Year_field]
Bill_No          text  maxchar 50
Villas           → admin.Villa[ Hide_From_Payments == false ]  LIST
Location         → admin.Location
Head_Office      → admin.Head_Office
Booking_No       → fb.Booking[ Villa_Name.ID == input.Villas ]     ← CROSS-APP
Amount           INR commadotindian   displayname "Gross Amount"
TDS              → TDS[ Status == "Active" ]  displayformat [TDS_Name + " - " + TDS_Precentage]
TDS_Amount       INR
Total_Amount     INR  displayname "Invoice Amount"   must have
GST_Amount       INR
Paid_Amount      INR                  ← currency here; a CHECKBOX on Payment (defect 27)
Payable_Amount   INR
Adjusted_Amount  decimal
Split_Equally    checkbox
Books_ID         number maxchar 19
Status           picklist {Draft, Paid, Partially Paid, Overdue, Payment Inprogress, Overpaid}
Payments_Scheduled     → Payments_Scheduled
Salary_Payout_Schedule → Salary_Payout_Schedule
```

**`Amount_Category` grid** — invoice line items
```
Bill_For text · Amount INR · GST → Tax · GST_Amount INR · Total_Amount INR
```

**`Split_Payment` grid** — allocation across villa × category × cycle
```
Villa_Name    → admin.Villa
Item_Category → Item_Category
Billing_Cycle → Billing_Cycles
Amount               INR
TDS_Amount           INR
GST_Amount           INR
Total_Amount         INR
Backend_TDS_Amount   INR    ┐ running UNPAID balance per leg,
Backend_GST_Amount   INR    ├ decremented on each payment.
Backend_Total_Amount INR    ┘ Resolves the doc §6.2 [TODO].
Percent              percentage
Partial_Paid         checkbox
```

### Payment

~130 top-level fields; ~40 render. The rest is legacy.

```
COA              → COA[ Hide == true ]        ← INVERSE of Bills (defect 28)
Payment_No       text
Requested_Date   date
Backend_Payment_Date date                     ← preserves original before bank match
Haewaya_TimeStamp    text                     ← a DATE stored as TEXT
Payment_Date     date
Due_Date         date
Item_Category    → Item_Category[ Disable == false ]  LIST
Master_Category  → Master_Category  LIST
Payment_Mode     picklist {Online, Offline}
Bank_Name        → COA[ Bank == true ]
Status           picklist {Draft, Submit for Approval, Sent for Approval,
                           Send for Approval, Approved, Approval Rejected,
                           Approval Not Required, Paid}   initial "Draft"
Payment_Status   picklist {Pending, paid, Cancelled, Reverse}
                 ← "Open" is WRITTEN by Create_Payment but NOT DECLARED (defect 20)
Vendor_Name      → Vendor_Master[ Main_Primary.Main_Primary is not null ]
Bill_No          text
Bill_No1         → Bills[ Vendor_Name.ID == input.Vendor_Name
                          && Status == "Draft" || Status == "Overdue"
                          || Status == "Partially Paid" ]  LIST
                 ← note the unbracketed &&/|| — the vendor filter only binds to "Draft"
Vendor_Order_Booking_No → fb.Vendor_Order_Booking[ Vendor_Name.ID == input.Vendor_Name
                          && Payment_Status == "Unpaid" || Payment_Status == "Partially Paid" ]
Booking_No       → fb.Booking[ Villa_Name.ID == input.Villa_Name ]
Timestamp_Date   datetime hh:mm:ss
Verification_Call  upload file  browse = local_drive, google_docs
Villa_Name       → admin.Villa[ Hide_From_Payments == false ]  LIST
Multi_Location   → admin.Location  LIST        ← duplicate of Location
Location         → admin.Location
Head_Office      → admin.Head_Office
Amount           INR  decimalplace = 3         ← THREE decimals (defect 29)
TDS              → TDS  (unfiltered — Bills filters Status=="Active")
TDS_Amount       INR
PT               INR
ESI              INR  displayname "ESIC"
PF               INR
Payable_Amount   INR
Invoice_Amount   INR
Original_Amount  INR
GST_Needed       checkbox
GST_Type         picklist {Predefined GST, Enter Manully}
GST              → Tax
GST_Amount       INR
Split_Equally    checkbox
Payment_Reference_Number  text  displayname "Haewaya UTR Number"
                 ← packs two values: "118103052206,15038"
Payment_Reference_Number1 text  displayname " Payment Reference Number"
Bills1           upload file  file count 10   displayname "Bills doc"
Supporting_Documents upload file  file count 10
OCR              textarea  AI_Properties( fieldtype = OCR  basefield = Verification_Call )
Billing_Year     number maxchar 4
Billing_Months   list of month names
Billing_Cycles   → Billing_Cycles  LIST
Next_total_Months number      ← generates N consecutive cycles; shown only for
                                STAFF LOAN / F&B STAFF LOAN
Approver_1/2/3   → admin.Employee_Master        ← vestigial, always empty (doc 8.4)
Approver_Level1  radiobuttons {Approved, Rejected}
Approver_Level_2 radiobuttons {Approved, Rejected}
Approver_Level_3 radiobuttons {Approved, Rejected}
Radio            radiobuttons {Choice 1, Choice 2, Choice 3}   ← untouched Creator default
Messageid          text        ← WhatsApp msg id, level 1
Messageid_Level_2  text
Messageid_Level_3  text
Whatsapp_Status    text
Whatsapp_Remarks   textarea    ← concatenated audit log
Approved_Persons   textarea    ← concatenated audit log
Ground_team        textarea
Bank_Reconcilation checkbox
Withdrawal_Matched checkbox
Deposit_Matched    checkbox
bank_matched       checkbox
Haewaya_Matched    checkbox
Bank_name_changed  checkbox
systemUpdating     checkbox    ← guard flag, suppresses cascade handlers
staffLoanProcessed checkbox    ← guard flag
Link_Updated       checkbox
Delete_Record      checkbox    ← gates hard delete
Paid_Amount        checkbox    ← A CHECKBOX. Currency on Bills. (defect 27)
Marked_as_Paid     checkbox  displayname "Paid"
Multiple_Villa     checkbox
Verified           checkbox
Approved           checkbox
Accounts_Bills     checkbox    ← true when created from a Bill
Total_bill_amount  checkbox  +  bill_total_amount  INR
Check_Total_Split_Amount checkbox  +  Total_Split_Amount INR
Bill_Pay_Full      checkbox
recoverable        checkbox
External_Payment   checkbox
Salary_Payout      checkbox
A B C D            checkbox  ×4          ← duplicate-review flags
A/B/C/D_Updated_User_Name   text  ×4
A/B/C/D_Updated_User_Login  email ×4
Payment_folder     text        ← WorkDrive folder id
Link               url         ← WorkDrive public link
User_Made_Paid     text
Books_ID           number maxchar 19
Books_Expense_ID   text
Sync_Note          text
Haewaya_ID         text
F_B_Payments       → fb.Expenses
F_B_Expenses       → fb.Expenses  LIST  bidirectional = Payment_No1
Payments_Scheduled → Payments_Scheduled
Payment_Request    → Payment_Request  bidirectional = Payment_No
Bank_Transactions  → Bank_Transactions
Salary_Payout_Schedule → Salary_Payout_Schedule
Salary_Payouts     → Salary_Payouts
```

**`Bill_Payments` grid** — allocation, not documents
```
Bill_No  → Bills · Bill_No1 → fb.Vendor_Order_Booking · Villa_Name → admin.Villa
Booking_No → fb.Booking · Check_In_Date date · Check_Out_Date date
Bill_Amount INR displayname "UnPaid Amount" · Payable_Amount INR · Pay_Full checkbox
```

**`Bills` grid** — repeating URL list (third document mechanism)
```
Bill  url
```

**`Split_Payments` grid** — note the extra statutory columns vs Bills
```
Villa_Name → admin.Villa · Billing_Cycle → Billing_Cycles · Item_Category → Item_Category
Amount        INR  displayname "Gross Amount"
PT_Amount     INR
ESIC_Amount   INR
PF_Amount     INR
TDS_Amount    INR
GST_Amount    INR
Total_Amount  INR  displayname "Payable Amount"
Percent       percentage
backend_Amount       INR  displayname "backend Amount"
Backend_TDS_Amount   INR
Backend_GST_Amount   INR
```

### Expenses_Bills — "Expenses & Bills" (the flattened ledger)

66 fields. **No edit page** — the list has a per-row `Update Expense` action.
`OnLoadCE` disables nearly everything; editable are Villa, Location, Head Office,
Bank, Billing Cycle, Vendor, Master/Item Category, Booking No, VOB No, COA,
PT/ESIC/PF and **`Old_Billing_Cycles`**. It is a re-classification tool.

```
Type_field       radiobuttons {Expense, Bill}
Bill_Date · Due_Date · Payment_Date   date
Billing_Cycle    → Billing_Cycles
Bill_No          text
Vendor_Name      → Vendor_Master
Location → admin.Location · Villa_Name → admin.Villa · Head_Office → admin.Head_Office
Bank_Name        → COA[ Account_Type == "bank" || Account_Type == "cash" ]
Master_Category  → Master_Category      (single, not list — unlike Bills)
Item_Category    → Item_Category        (single)
Booking_No       → fb.Booking
Vendor_Order_Booking_No → fb.Vendor_Order_Booking
COA              → COA
Timestamp_Date   datetime
Verification_Call upload file
Expense_By · Payment_By · Payment_Reference_Number   text
Status           picklist {Draft, Bill Generated, Paid, Partially Paid, Overdue}
Accounts_Remarks · Management_Remarks · Particulars   textarea
                 ← Particulars is a PACKED STRING: "{Category} - {note},{date},{date}"
                   One row contains a customer's full bank account in plaintext.
Gross_Amount     INR    ┐
TDS_Amount       INR    ├ Gross == Amount == Net_Paid when GST and TDS are zero
Net_Paid_Amount  INR    │ (defect: three columns holding the same number)
GST_Amount       INR    │
Amount           INR    ┘
PT · ESIC · PF   INR
Bills            grid( Bill url enable linkname )
Bill             → Bills
Payment          → Payment
CA_Email         email
Books_ID         number
F_B_Expenses     → fb.Expenses  bidirectional = Expenses_Bills
Bill_Available   checkbox        ← THE DELETE-SWEEP KEY (defect 30)
Bank_Reconcilation checkbox  displayname "Recon Expense"
Duplicate        checkbox
Link             url
Last_Updated_User text  displayname "Last Updated By"
A B C D + *_Updated_User_Name + *_Updated_User_Login
Updated_By_Widget checkbox
Old_Billing_Cycles → Billing_Cycles     ← audit trail for re-classification
```

---

## Bank reconciliation (the new engine)

### Bank_Match_Line — the junction table. **Model the rebuild on this.**
```
Bank_Transaction      → Bank_Transactions  displayformat [Transaction_ID]
Payment               → Payment            displayformat [Payment_No]
Direction             picklist {Withdrawal, Deposit}
Matched_Amount        decimal
Bank_Account          → COA
Match_Source          picklist {Manual, Auto Opposite, Suggested}
Match_Group           number
Original_Payment_Date date        ← restored on full unmatch
Is_Active             checkbox    ← SOFT DELETE
Matched_On            date
Matched_By            text  initial value "${zoho.loginuserid}"
```

### Bank_Reconcile — the matching UI (`store data in zc = false`)
```
RecID number · Bank_Account text · Transaction_Date date · Description textarea
Matched_Amount decimal · Match_Status text · Direction text · Txn_Type text
Reference_No text · Transaction_Amount decimal · Pending_Amount decimal
Best_Match textarea · Find_Combination checkbox · Combination_Result textarea
— Search filters —
Payment_No text · Location/Vendor/Item_Category/Villa picklist {None}   ← populated at runtime
Date_From · Date_To date · Amount_From · Amount_To INR
Include_Smaller_Amounts checkbox · Search checkbox · Reset checkbox
Available_Payments grid( PayID number · Match checkbox · Tier text · Score number
                         Payment_No · Payment_Date · Vendor · Villa · Location
                         Particulars · Bank_Name · COA · Item_Category · Amount decimal
                         Other_Leg textarea )
Selected_Payments  grid( PayID · Payment_No · Payment_Date · Vendor · Particulars
                         Amount decimal · Source text · Remove checkbox )
Opposite_Txn_Info text · Opposite_Txn_ID number · Auto_Match_Opposite checkbox
Unmatch_All checkbox
```

### Bank_Transactions
```
Reference_No text · Date_field date · Transaction_ID number maxchar 19
Amount · Bank_Charges · Gross_Amount · Deposit · Withdrawal · Matched_Amount
Pending_Amount   all INR commadotindian
Account_Name  → COA
Account_Type  text
Transaction_Type picklist {deposit, refund, transfer_fund, card_payment,
    sales_without_invoices, expense_refund, owner_contribution, interest_income,
    other_income, owner_drawings, sales_return}
Status picklist {All, uncategorized, manually_added, matched, excluded, categorized,
    Withdrawal Matched, Deposit Matched, duplicate}
    ← "Creator Matched" and "Partially Matched" are WRITTEN but NOT DECLARED
Source text · Debit_Credit picklist {debit, credit}
Item_Category → Item_Category LIST · Matched_Payments → Payment LIST
Description text · Category textarea displayname "Reason"
Get_Transaction · Create_Payment · Personal_Payment · Payment_Created
Withdrawal_Matched · Deposit_Matched      checkboxes
Matching_Transactions grid( Match checkbox · Date_field · Contact_Name
    Transaction_Type · Transaction_Number · Amount · Matched_Amount
    Pending_Amount · Transaction_ID )
```

---

## Payroll

### Salary_Payouts
```
must have Location   → admin.Location
State                → admin.State
must have Villa_Name → admin.Villa[ Location.ID == input.Location
                                    && Hide_From_Payments == false ]  LIST
must have Amount  INR  displayname "Base Pay"
HRA · CC          INR
Make_Calculation  radiobuttons {Automatic, Manual}  initial "Automatic"
Total_Amount      INR
must have COA     → COA[ Hide == true ]
Bank_Name         → COA[ Bank == true ]
Vendor_Name       → Vendor_Master
Employee_Designation → admin.Employee_Designation
Item_Category     → Item_Category[ Item_Category == "STAFF SALARY"
                                   || Item_Category == "F&B SALARY" ]
Master_Category   → Master_Category
must have Payment_Type picklist {Payment}  initial "Payment"
PF_Status   picklist {Yes, No}  initial "No"     ← maintained BY HAND
ESIC_Status picklist {Yes, No}  initial "No"     ← consider deriving
Gender      picklist {Male, Female}    ← load-bearing: Maharashtra PT threshold
Age         number                     ← load-bearing: under-65 PT exemption
Entity      → Vendor_Master  displayformat [Entity]
true_field  checkbox  displayname "true"
Salary_Months grid( Start_Month → Billing_Cycles · End_Month → Billing_Cycles )
Payouts grid(
    Create_Payment checkbox · Billing_Cycle → Billing_Cycles
    Payment_Date · Due_Date date
    Number_of_Days_worked number
    Salary · Base_pay · HRA · CC            INR
    EMPLOYER_PF · EMPLOYER_ESIC · PT
    EMPLOYEE_PF · EMPLOYEE_ESIC             INR
    Staff_Advance · Staff_Loan · Penalty · Other_Expenses  INR
    CTC · Payable_Amount                    INR
    Payment_No → Payment  displayformat [Payment_No]
    Status     → Payment  displayformat [Status]   ← reads through the lookup
)
```

Editable per payout row: `Create_Payment`, `Billing_Cycle`, `Payment_Date`,
`Number_of_Days_worked`, `Penalty`, `Other_Expenses`. Everything else derives.
`Staff_Advance` / `Staff_Loan` are pulled from `Expenses_Bills` by vendor + cycle.

### Salary_Payout_Schedule
Same shape plus `Start_Date`/`End_Date`/`Due_Date`/`Payment_Date`, `TDS`, `GST`,
`Billing_Cycle`, `Status {Draft, Due, Click to Proceed}`, `Payment_Type {Payment,
Bill & Payment}`, and a `Payouts` grid that also carries `Bill_No → Expenses_Bills`
and `Status1 → Expenses_Bills[Status]`.

---

## Schedule payments

### Schedule_Payment
```
Location → admin.Location
Villa_Name → admin.Villa[ Location.ID == input.Location && Hide_From_Payments == false ] LIST
must have Start_Date · End_Date · Due_Date · Payment_Date   date
must have Amount INR · TDS → TDS · Total_Amount INR · GST → Tax
Vendor_Name → Vendor_Master · Item_Category → Item_Category · Master_Category → Master_Category
must have Payment_Type picklist {Payment, Bill & Payment}
must have COA → COA[ Hide == true ]
Bank_Name → COA[ Bank == true ]
must have Status picklist {Due, Click to Proceed}
    ← ALL 813 records sit at "Click to Proceed". Parent status never advances;
      the CHILD status is the real one (doc 10.4).
Payment_Schedule grid → Payments_Scheduled  bidirectional = Schedule_Payment
```

### Payments_Scheduled
```
Date_field · Due_Date date · Billing_Cycles → Billing_Cycles
Amount · Due_Amount · Total_Due   INR
GST → Tax · TDS → TDS
Remarks textarea            ← REQUIRED when Due_Amount != Amount. Good rule.
Schedule_Payment → Schedule_Payment  displayformat [Payment_Type]
    ← displays the PARENT'S TYPE, not its identity (display-format defect)
Status picklist {Due, Click to Proceed, Paid, Draft, Submit for Approval,
                 Sent for Approval, Send for Approval, Approved,
                 Approval Rejected, Approval Not Required}
Bills upload file file count 10
— payroll block —
Loan_deduction · Advance_deduction · Penalty · Days_deduction
PF · PT · ESIC · Excess_Amount     INR
No_Of_Days_Not_Worked number  displayname "Number of Days Worked"
    ← MISNOMER, NOT A BUG. The math subtracts it from month length, so it
      correctly means days WORKED. RENAME THE FIELD, KEEP THE MATH (doc 10.3).
```

---

## Master data (in Accounts)

```
COA
  Hide checkbox displayname "COA" · Account_Name · Account_Type text
  Account_Code text · Account_ID number maxchar 19 · Bank checkbox
  CA_Name → admin.CA_Master  bidirectional = Bank · Ekostay_ID text
  ← Account_Type is FREE TEXT holding "bank","cash","expense","other_asset",
    "other_current_asset". Approval routing branches on it (doc 8.1).
  ← Account_Name values include {Expense, Accounts Payable, Security Deposit,
    Payment Reverse, Husain Cash, Staff Loan, ...}. The downstream tracker
    modelled these AS the domain — they are values, not the type (doc 4.1).

Master_Category
  F_B checkbox displayname "F&B"   ← THE F&B FLAG. Filter on this, not on
                                     master_category == 'F&B' (doc 4.2)
  Master_Category text · Haewaya_ID text

Item_Category
  Item_Category text (upper-cased by OnInputItemCategoryCE)
  Master_Category → Master_Category
  Vendor_Name → Vendor_Master LIST
    help: "If vendor is selected, items will be visible only for the selected vendor."
  COA → COA · Bank_Name → COA[ Bank == true ]
  Expense_Type picklist {Direct, Indirect}
  Exclude_for_Profit · Exclude_for_Observation   checkbox
  Variance percentage
  Exclude_Item_Category checkbox
  Disable checkbox displayname "Disallow Manual Creation"
  Haewaya_ID text
  ← THIS master is the single source of truth for the classification flags.
    The tracker was building a parallel Settings table (doc 4.3).

Billing_Cycles
  Month_field picklist {January..December}  ← FULL ENGLISH MONTH NAMES
  Year_field  TEXT                          ← a year stored as text
  MonthIndex  number = year*100 + monthNo    (maintained by OninputMonCE/OninputYearCE)

Tax    Tax_Name · Tax_Type text · Tax_Precentage percentage · Tax_ID number
       ← Tax_Type values: "tax" (IGST, single) and "tax_group" (CGST+SGST).
         The group path halves, rounds each half, then re-adds.
TDS    TDS_Name · TDS_Precentage percentage · Books_ID number
       Status picklist {Active, Expired}

Auto_Numbers   (single record)
  Payment_Series/No · Books_Payment_Series/No
  Haewaya_Series/No · External_Payment_Series/No
  ← FOUR PARALLEL SERIES. Live data confirms all four:
    EKS/PY/20796 · EKS/Haewaya/31579 · REFUND-stay-313855 · EKS/API/…
    REBUILD: one series, origin as a separate field.

Vendor_Master        see doc 13A — 4 sections, Employee Details is a full HR record
Approval             the 3-level amount-banded matrix, see doc 8.2
Pending_Approvals    Approvers LIST · Preferred_Approver · Payment_No → Payment
                     Status picklist {Sent for Approval, Level1 Partially Approved,
                       Level1 Approved, Sent for Level2 Approval, Level2 Partially
                       Approved, Level2 Approved, Sent for Level3 Approval,
                       Level3 Partially Approved, Approved}
                     Approval_Level text · Next_Level_Approval_Required checkbox
                     Approval_Type picklist {All, Any}
                     Approved_By grid( Approver → Employee_Master · Approval_Level
                                       text · Approved checkbox )
Block_Payment        Date_field date  (single record; blocks Payment_Date before it)
Sync_Locks           unique Lock_Key text          ← the mutex
Expense_Observation  Location/Villa_Name/Head_Office → admin.*
                     Amount INR · Expense_Type picklist {Low, High}
                     Month_Year → Billing_Cycles · Observation_Notes textarea
                     Attachment upload file
```

## Backend_Expenses — the provider landing table

**140 top-level fields, 136 declared `type = text`** — every amount, date and boolean
included. Not a designed form; a landing table for a payment provider's feed. The
detail panel lists fields alphabetically, confirming no layout was ever applied.

Provider identity from field names: `rzp_payout_id` (Razorpay), `rbl_trn_id` (RBL
Bank), `bbps_txn_ref_id` (Bharat BillPay), `pg_order_id`, `pg_payment_id`,
`dyn_qr_ref_id`, `webhook_resp`, plus `fk_hccc_id`, `fk_m_hccc_id`, `fk_order_id`,
`fk_safe_id`.

**A second, parallel approval engine**, entirely separate from the §8 matrix:
`lvl_one_amt/msg/name` … `lvl_four_*`, `verify_by_lvl_one..four`,
`verify_lvl_one..four`, `lvl_one_approve_msg/time` … four,
`lvl_verification_status`, `is_admin_approval_require`, `approve_status`,
`approve_by`, `approval_txt`.

**Nine `cron_event_*` flags** as a state machine: `paid`, `captured`, `reversed_cr`,
`reversed_dr`, `bill_upload`, `bill_verified`, `admin_verified`, `duplicate_bill`,
`api_charges`.

Links out: `Payment` → one payment. `Matched_Payments` → a **LIST** of payments
(doc §13B [TODO]: does one backend expense ever match several?).

Three fields exist twice, once `text` and again `textarea`, with the typo preserved
in both: `multipe_hccc_names`/`…1`, `remark_txt`/`…1`, `receiver_details`/`…1`.

Six undecodable fields: `olab`, `olar`, `olaret`, `olart`, `olas`, `olat`.

Dedup support added since the doc: `dup_checked` checkbox, `dup_key` number, and
`Backend_Expenses_Mail_Duplicates()` which CSVs duplicates to
`vishwas@coderizeinfotech.com`.

**Rebuild recommendation stands (doc 13B.2):** typed staging table, read-only except
`Payment` / `Matched_Payments` / `dup_checked` / `dup_key`, surfaced inside Bank
Reconciliation, `receiver_details` and `receiver_upi_id` restricted by role.

## Revenue share (Eko_RS_*) — not in the 08-Aug doc

```
Eko_RS_App_Config     must have Config_Singleton text
                      GST_Slabs_Json · DoubleTick_API_Key · Default_Clusters_Json
                      Booking_Overrides_Json · Cached_Access_Token   textarea
                      DoubleTick_From_Number · DoubleTick_Template_Name
                      DoubleTick_From_Number1 · PDF_Host_Url_Override1
                      Pin_Hash · DoubleTick_Template · Analytics_Refresh_Token
                      Token_Expiry_Epoch   text
                      PDF_Host_Url_Override url
                      !! SECRETS IN PLAIN TEXT FIELDS. Account Team-Executive has
                         read access to Eko_RS_App_Config_Report.
Eko_RS_Settings       must have Villa_ID · Villa_Name text
                      Damage_Recovery_Type picklist {split, owner}  (×2, duplicated)
                      Owner_Banks · Ekostay_Banks · GST_Clusters_Json textarea
                      Default_Others_GST_Pct decimal
                      Owner_TDS_Pct picklist {0,1,2,19}
                      Owner_Payout_GST_Pct picklist {0,5,12,18}
                      Share_Source · Inner_Circle checkbox
Eko_RS_Statements     Villa_ID · Villa_Name · Period · Statement_Key text
                      Status picklist {Generated, Finalized}
                      Deductions_Json · Snapshot_Json · Notes textarea
                      Generated_At · Finalized_At datetime
                      Net_Payout · Owner_GST_Amount · TDS_Amount
                      Net_Payout_After_Tds · Owner_Revenue · Owner_Expenses INR
                      Booking_Count number
Eko_RS_Flags          Statement_Key · Villa_Name · Period · Booking_No · Flag_Type
                      Location · Flag_Key · Persisted_At text
                      Resolved checkbox · Check_In · Check_Out date
                      Detail · Remarks textarea
Eko_RS_Send_Log       Statement_Key · Villa_Name · Period · Owner_Name/Phone/Email text
                      Channel picklist {whatsapp, email}
                      Status picklist {ok, error}
                      Error_Msg · Response_Json textarea · Sent_At1 text
Eko_RS_Pdf_Staging    Pdf_File upload file · Statement_Key1 text
```

## Utility / popup forms (`store data in zc = false`)

`Add_Payment_Reference_Number` (RecID, pending_Approvel, Payment_Reference_Number —
**this is where a payment becomes Paid**) · `Create_Payment` · `Match_Transaction` ·
`Manual_Update_Bank` · `Update_Expense` · `Update_Payment` (the A/B/C/D duplicate
flags with their real meanings: "A- Same Bill, Same Vendor", "B- Same Bill, Same
Amount", "C-Same amount,Vendor, Category", "D- Same timestamp, Same amount, Same
location") · `Personal_Payment` · `Preferred_Approver` · `Regenerate_Payment` ·
`Error_Message` (displayname "Message")

## Other forms

`Backend_Payments` (a text-typed mirror of Payment, fed by the refund provider;
`PaymentRefund.Payment` converts it) · `Deleted_Payments` (the archive; nearly all
Payment fields plus `Deleted_By_User`, `Deleted_Time_User`, `Created`) ·
`Match_Payments` / `Debit_Match_Payments` (the OLD withdrawal/deposit matchers) ·
`Husain_Office_Module` · `Flagged_Payments` (Flag_Type {Similar Spend, Single Vendor,
Expense Jump, Maintenance Outlier}, Severity {High, Medium, Low}, Status {New,
Reviewed, Dismissed, Confirmed}) · `Payment_Request` ·
`Zoho_app_pointers_Payment_Apr_Jun_1` (an import staging form with ~1,500
hardcoded picklist values — invoice numbers, circle codes, employee names)

---

## Hardcoded literals to move to config

| Literal | Where |
|---|---|
| `60040119506` | Books organization_id, ~20 call sites |
| `292482000000124003` | F&B master category, excluded from Bills Item_Category and admin.Villa.Master_Category |
| `292482000003927068`, `292482000000130718` | zero-GST tax records — resolve via `Tax.Tax_Precentage == 0` |
| `292482000000130722`, `292482000003927070` | the non-zero GST defaults picked by vendor GST prefix "27" |
| `oaggo5f59c3be38d04f0bb8e3b02c305ec791` | WorkDrive Payments parent folder |
| `cel9edf8d206298804b52b0c05c0403b74e63` | WorkDrive revenue-share parent |
| `oaggo6087760ca6eb49999decddfe04d42c00` | app variable `Payments_Folder_ID` |
| `443703000000062565` | Analytics workspace |
| `60042406851` | Analytics org |
| `919137817176`, `918169019090` | WhatsApp from-numbers |
| `https://hywdocs.s3.ap-southeast-1.amazonaws.com/` | provider asset prefix |
| `https://expense.server.ekostay.com/api/zoho/payment-deleted` | external webhook |
| `2508841000000218097` | a bank Account_ID, hardcoded in ScheduleMatchTransaction12 |
| `husain@ekostayhospitality.com` | permission check in `Accounts.DeletePermanentlyTrash` |
| `varun@ekostayhospitality.com` | UI gate in `Payment.OnLoadCE` |
| `husain_ekostay1` | conditional-format check on All_Husain_Office_Modules |
| `vishwas@coderizeinfotech.com` | duplicate-report recipient |

---

## Corrections from the live server — 13-Aug-2026

Read-only inspection of `server.ekostay.com`. Full detail in `../DB_FINDINGS.md`.
These override the field notes above where they conflict.

### Money columns → `DECIMAL(16,2)`

Every money column across all three live databases is `decimal`, never a float.
`serv_ekostay_expense.payouts` — the table that mirrors Creator's `Payment` — uses
`decimal(16,2)`. Match it rather than introducing a narrower `(14,2)`.

Applies to every `INR` and `decimal` field in this document.

### `Villa.Category` — Creator says `Luxery`, Analytics says `Luxury`

```deluge
// Admin.ds:670 — the form declaration
Category ( values = {"Gold","Luxery","Original"}  others option = true )
```

The expense tracker holds **`Luxury`**, correctly spelled, 8,916 rows — and its
ingest does no mapping (`ZohoSyncService.php:187` passes the value through). Both
the legacy CSV import and the live API sync carry the corrected form.

**So a transformation layer exists between Creator and Zoho Analytics that no DS
export reveals.** There may be others.

Rebuild:

```sql
villa_category        varchar(32)   -- Creator's value, verbatim: 'Luxery'
villa_category_alias  varchar(32)   -- the corrected form Analytics serves: 'Luxury'
```

Store Creator's spelling; expose the alias for anything that talks to Analytics or
the tracker. **Never join on either label — join on villa id.** A label join drops
every Luxury villa silently, the same failure mode as the CA report's
name-substring filter (`Accounts_PERMISSIONS.md`).

### `COA.Hide` — misnamed, not inverted

`Payment.COA` filters `COA[Hide == true]`; `Bills.COA` does not filter at all. That
looked like an inverted condition. Live `payouts.coa_type` settles it — every COA
type appears through the Payment picker:

```
expense · accounts_payable · other_expense · bank · cash · other_asset
```

So `Hide == true` means **"available for selection"**, not "hidden".

Rebuild: name the column `selectable`, keep the behaviour identical, and record
the Creator name in a comment so the mapping is traceable.

### Our payments table → `vendor_payments`

Two other tables are already called `payments` on that host, and neither is ours:

| Table | Domain |
|---|---|
| `serv_ekostay_expense.payments` | guest-side **inbound** — 105k rows, `payment_type ∈ {bank_transfer, airpay, CASH, airnb, qr_scanner, mmt, razorpay, adjusted}` |
| `serv_ekostay_expense.payouts` | vendor-side **outbound** — 50k rows, mirrors Creator's `Payment` |
| ours | vendor-side outbound, with split legs |

Name ours `vendor_payments`. `payouts` is flattened — header only, no allocation —
so `Split_Payments` is ours alone to model.

### Confirmed unchanged

- **`Payable = Invoice − TDS`, no `Paid_Amount` term.** 16,285 of 16,405 live rows
  have payable equal to invoice; the rest differ by TDS. Our formula is right.
- **`Payment_Status = "Open"`** stays, with its dirty-value marker. It is absent
  from `payouts.status`, but that column maps to Creator's `Status` — the tracker
  never ingests `Payment_Status`, so absence is not evidence.
- **Both `"Sent for Approval"` (18 rows) and `"Send for Approval"` (3 rows)** exist
  in live data. The duplicate enum is real, not a DS-export artefact.
- **`bank_match_lines` as a junction table.** The settlement system stores
  `bank_transactions.zoho_match_payments` as a **text** blob — the same
  list-in-a-column pattern `Bank_Match_Line` fixed in Creator. Nothing to reuse;
  our design stands.
