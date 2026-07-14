<?php

namespace App\Services\TallyExport;

use App\Enums\StatementType;
use App\Models\AccountHead;
use App\Models\Company;
use App\Models\ImportedFile;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TallyExportService
{
    /** @var array<string, int> */
    private array $voucherCounters = [];

    // Boolean flags emitted on every Journal voucher, keyed by Tally field name.
    /** @phpstan-var array<string, string> */
    private const JOURNAL_BOOLEAN_FLAGS = [
        'DIFFACTUALQTY' => 'No', 'ISMSTFROMSYNC' => 'No', 'ISDELETED' => 'No',
        'ISSECURITYONWHENENTERED' => 'No', 'ASORIGINAL' => 'No', 'AUDITED' => 'No',
        'ISCOMMONPARTY' => 'No', 'FORJOBCOSTING' => 'No', 'ISOPTIONAL' => 'No',
        'USEFOREXCISE' => 'No', 'ISFORJOBWORKIN' => 'No', 'ALLOWCONSUMPTION' => 'No',
        'USEFORINTEREST' => 'No', 'USEFORGAINLOSS' => 'No', 'USEFORGODOWNTRANSFER' => 'No',
        'USEFORCOMPOUND' => 'No', 'USEFORSERVICETAX' => 'No', 'ISREVERSECHARGEAPPLICABLE' => 'No',
        'ISSYSTEM' => 'No', 'ISFETCHEDONLY' => 'No', 'ISGSTOVERRIDDEN' => 'No',
        'ISCANCELLED' => 'No', 'ISONHOLD' => 'No', 'ISSUMMARY' => 'No',
        'ISECOMMERCESUPPLY' => 'No', 'ISBOENOTAPPLICABLE' => 'No', 'ISGSTSECSEVENAPLICABLE' => 'No',
        'IGNOREEINTVALIDATION' => 'No', 'CMPGSTISOTHTERRITORYASSESSEE' => 'No',
        'PARTYGSTISOTHTERRITORYASSESSEE' => 'No', 'IRNJSONEXPORTED' => 'No',
        'IRNCANCELLED' => 'No', 'IGNOREGSTCONFLICTINMIG' => 'No', 'ISOPBALTRANSACTION' => 'No',
        'IGNOREGSTFORMATVALIDATION' => 'No', 'ISELIGIBLEFORITC' => 'Yes',
        'IGNOREGSTOPTIONALUNCERTAIN' => 'No', 'UPDATESUMMARYVALUES' => 'No',
        'ISEWAYBILLAPPLICABLE' => 'No', 'ISDELETEDRETAINED' => 'No', 'ISNULL' => 'No',
        'ISEXCISEVOUCHER' => 'No', 'EXCISETAXOVERRIDE' => 'No', 'USEFORTAXUNITTRANSFER' => 'No',
        'ISEXER1NOPOVERWRITE' => 'No', 'ISEXF2NOPOVERWRITE' => 'No', 'ISEXER3NOPOVERWRITE' => 'No',
        'IGNOREPOSVALIDATION' => 'No', 'EXCISEOPENING' => 'No', 'USEFORFINALPRODUCTION' => 'No',
        'ISTDSOVERRIDDEN' => 'No', 'ISTCSOVERRIDDEN' => 'No', 'ISTDSTCSCASHVCH' => 'No',
        'INCLUDEADVPYMTVCH' => 'No', 'ISSUBWORKSCONTRACT' => 'No', 'ISVATOVERRIDDEN' => 'No',
        'IGNOREORIGVCHDATE' => 'No', 'ISVATPAIDATCUSTOMS' => 'No', 'ISDECLAREDTOCUSTOMS' => 'No',
        'VATADVANCEPAYMENT' => 'No', 'VATADVPAY' => 'No', 'ISCSTDELCAREDGOODSSALES' => 'No',
        'ISVATRESTAXINV' => 'No', 'ISSERVICETAXOVERRIDDEN' => 'No', 'ISISDVOUCHER' => 'No',
        'ISEXCISEOVERRIDDEN' => 'No', 'ISEXCISESUPPLYVCH' => 'No', 'GSTNOTEXPORTED' => 'No',
        'IGNOREGSTINVALIDATION' => 'No', 'ISGSTREFUND' => 'No', 'OVRDNEWAYBILLAPPLICABILITY' => 'No',
        'ISVATPRINCIPALACCOUNT' => 'No', 'VCHSTATUSISVCHNUMUSED' => 'No',
        'VCHGSTSTATUSISINCLUDED' => 'No', 'VCHGSTSTATUSISUNCERTAIN' => 'No',
        'VCHGSTSTATUSISEXCLUDED' => 'No', 'VCHGSTSTATUSISAPPLICABLE' => 'No',
        'VCHGSTSTATUSISGSTR2BRECONCILED' => 'No', 'VCHGSTSTATUSISGSTR2BONLYINPORTAL' => 'No',
        'VCHGSTSTATUSISGSTR2BONLYINBOOKS' => 'No', 'VCHGSTSTATUSISGSTR2BMISMATCH' => 'No',
        'VCHGSTSTATUSISGSTR2BINDIFFPERIOD' => 'No', 'VCHGSTSTATUSISRETEFFDATEOVERRDN' => 'No',
        'VCHGSTSTATUSISOVERRDN' => 'No', 'VCHGSTSTATUSISSTATINDIFFDATE' => 'No',
        'VCHGSTSTATUSISRETINDIFFDATE' => 'No', 'VCHGSTSTATUSMAINSECTIONEXCLUDED' => 'No',
        'VCHGSTSTATUSISBRANCHTRANSFEROUT' => 'No', 'VCHGSTSTATUSISSYSTEMSUMMARY' => 'No',
        'VCHSTATUSISUNREGISTEREDRCM' => 'No', 'VCHSTATUSISOPTIONAL' => 'No',
        'VCHSTATUSISCANCELLED' => 'No', 'VCHSTATUSISDELETED' => 'No',
        'VCHSTATUSISOPENINGBALANCE' => 'No', 'VCHSTATUSISFETCHEDONLY' => 'No',
        'VCHGSTSTATUSISOPTIONALUNCERTAIN' => 'No', 'VCHSTATUSISREACCEPTFORHSNDONE' => 'No',
        'VCHSTATUSISREACCEPHSNSIXONEDONE' => 'No', 'PAYMENTLINKHASMULTIREF' => 'No',
        'ISSHIPPINGWITHINSTATE' => 'No', 'ISOVERSEASTOURISTTRANS' => 'No',
        'ISDESIGNATEDZONEPART' => 'No', 'HASCASHFLOW' => 'No', 'ISPOSTDATED' => 'No',
        'USETRACKINGNUMBER' => 'No', 'ISINVOICE' => 'No', 'MFGJOURNAL' => 'No',
        'HASDISCOUNTS' => 'No', 'ASPAYSLIP' => 'No', 'ISCOSTCENTRE' => 'No',
        'ISSTXNONREALIZEDVCH' => 'No', 'ISEXCISEMANUFACTURERON' => 'No', 'ISBLANKCHEQUE' => 'No',
        'ISVOID' => 'No', 'ORDERLINESTATUS' => 'No', 'VATISAGNSTCANCSALES' => 'No',
        'VATISPURCEXEMPTED' => 'No', 'ISVATRESTAXINVOICE' => 'No', 'VATISASSESABLECALCVCH' => 'No',
        'ISVATDUTYPAID' => 'Yes',
        'ISDELIVERYSAMEASCONSIGNEE' => 'No', 'ISDISPATCHSAMEASCONSIGNOR' => 'No',
        'ISDELETEDVCHRETAINED' => 'No', 'VCHONLYADDLINFOUPDATED' => 'No',
        'CHANGEVCHMODE' => 'No', 'RESETIRNQRCODE' => 'No',
    ];

    /**
     * Generate Tally-compatible XML for transactions in an imported file.
     */
    public function exportForFile(ImportedFile $importedFile): string
    {
        $importedFile->loadMissing(['company', 'bankAccount']);

        /** @var Collection<int, Transaction> $transactions */
        $transactions = $importedFile->transactions()
            ->whereNotNull('account_head_id')
            ->with('accountHead')
            ->orderBy('date')
            ->get();

        return $this->generateXml($transactions, $importedFile->company, $importedFile->bankAccount?->name, $importedFile);
    }

    /**
     * Export selected transactions to Tally XML.
     *
     * @param  Collection<int, Transaction>  $transactions
     */
    public function exportTransactions(Collection $transactions): string
    {
        /** @var Transaction|null $firstTransaction */
        $firstTransaction = $transactions->first();
        /** @var ImportedFile|null $importedFile */
        $importedFile = $firstTransaction?->importedFile;
        $importedFile?->loadMissing(['company', 'bankAccount']);

        return $this->generateXml(
            $transactions,
            $importedFile?->company,
            $importedFile?->bankAccount?->name,
            $importedFile,
        );
    }

    /**
     * Generate the complete Tally XML envelope.
     *
     * @param  Collection<int, Transaction>  $transactions
     */
    private function generateXml(Collection $transactions, ?Company $company, ?string $bankLedgerName, ?ImportedFile $importedFile = null): string
    {
        $this->voucherCounters = [];
        $companyName = $company?->name ?? '';

        // Pre-decrypt the first transaction's raw_data once for isAllMastersExport().
        // This avoids a redundant Crypt::decrypt() call since generateVoucher() will
        // decrypt the same value again for each transaction in the loop below.
        /** @var Transaction|null $firstTransaction */
        $firstTransaction = $transactions->first();
        /** @var array<string, mixed>|null $firstRaw */
        $firstRaw = $firstTransaction !== null && $importedFile?->statement_type === StatementType::Invoice
            ? ($firstTransaction->raw_data ?? [])
            : null;

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<ENVELOPE>'."\n";
        $xml .= '  <HEADER>'."\n";
        $xml .= '    <TALLYREQUEST>Import Data</TALLYREQUEST>'."\n";
        $xml .= '  </HEADER>'."\n";
        $xml .= '  <BODY>'."\n";
        $xml .= '    <IMPORTDATA>'."\n";
        $xml .= '      <REQUESTDESC>'."\n";
        $reportName = $this->isAllMastersExport($transactions, $importedFile, $firstRaw) ? 'All Masters' : 'Vouchers';
        $xml .= '        <REPORTNAME>'.$reportName.'</REPORTNAME>'."\n";
        $xml .= '        <STATICVARIABLES>'."\n";
        $xml .= '          <SVCURRENTCOMPANY>'.$this->escapeXml($companyName).'</SVCURRENTCOMPANY>'."\n";
        $xml .= '        </STATICVARIABLES>'."\n";
        $xml .= '      </REQUESTDESC>'."\n";
        $xml .= '      <REQUESTDATA>'."\n";

        foreach ($transactions as $transaction) {
            $xml .= $this->generateVoucher($transaction, $company, $bankLedgerName, $importedFile);
        }

        if ($company && $transactions->isNotEmpty()) {
            $xml .= $this->generateCompanyFooter($company);
        }

        $xml .= '      </REQUESTDATA>'."\n";
        $xml .= '    </IMPORTDATA>'."\n";
        $xml .= '  </BODY>'."\n";
        $xml .= '</ENVELOPE>';

        return $xml;
    }

    /**
     * Generate a single Tally voucher XML element (Payment, Receipt, or Journal).
     */
    private function generateVoucher(Transaction $transaction, ?Company $company, ?string $bankLedgerName, ?ImportedFile $importedFile = null): string
    {
        $effectiveFile = $transaction->relationLoaded('importedFile')
            ? $transaction->importedFile
            : $importedFile;

        if ($effectiveFile?->statement_type === StatementType::Invoice) {
            /** @var array<string, mixed> $raw */
            $raw = $transaction->raw_data ?? [];

            if (isset($raw['buyer_name'])) {
                return $this->generateSalesVoucher($transaction, $company, $raw);
            }

            return $this->generateInvoiceJournalVoucher($transaction, $company, $raw);
        }

        $isDebit = $transaction->debit !== null;
        $amount = (float) ($isDebit ? $transaction->debit : $transaction->credit);
        /** @var Carbon $transactionDate */
        $transactionDate = $transaction->date;
        $date = $transactionDate->format('Ymd');
        /** @var AccountHead|null $accountHead */
        $accountHead = $transaction->accountHead;
        $headName = $accountHead?->name ?? 'Unknown';
        $bankName = $effectiveFile?->bankAccount?->name
            ?? $effectiveFile?->display_name
            ?? $bankLedgerName
            ?? 'Bank Account';
        $voucherNumber = $this->nextVoucherNumber('Journal');

        $xml = '        <TALLYMESSAGE xmlns:UDF="TallyUDF">'."\n";
        $xml .= '          <VOUCHER VCHTYPE="Journal" ACTION="Create" OBJVIEW="Accounting Voucher View">'."\n";
        $xml .= '            <DATE>'.$date.'</DATE>'."\n";
        $xml .= '            <VCHSTATUSDATE>'.$date.'</VCHSTATUSDATE>'."\n";
        $xml .= '            <NARRATION>'.$this->escapeXml($transaction->description ?? '').'</NARRATION>'."\n";
        $xml .= '            <VOUCHERTYPENAME>Journal</VOUCHERTYPENAME>'."\n";
        $xml .= '            <PARTYLEDGERNAME>'.$this->escapeXml($headName).'</PARTYLEDGERNAME>'."\n";
        $xml .= '            <VOUCHERNUMBER>'.$voucherNumber.'</VOUCHERNUMBER>'."\n";
        $xml .= $this->journalVoucherGstFields($company);
        $xml .= '            <NUMBERINGSTYLE>Auto Retain</NUMBERINGSTYLE>'."\n";
        // &#4; (U+0004, EOT) is Tally's sentinel value for "not applicable" enum fields.
        // It is forbidden in strict XML 1.0 but Tally requires it — do not replace with an empty string.
        $xml .= '            <CSTFORMISSUETYPE>&#4; Not Applicable</CSTFORMISSUETYPE>'."\n";
        $xml .= '            <CSTFORMRECVTYPE>&#4; Not Applicable</CSTFORMRECVTYPE>'."\n";
        $xml .= '            <FBTPAYMENTTYPE>Default</FBTPAYMENTTYPE>'."\n";
        $xml .= '            <PERSISTEDVIEW>Accounting Voucher View</PERSISTEDVIEW>'."\n";
        $xml .= '            <VCHSTATUSTAXADJUSTMENT>Default</VCHSTATUSTAXADJUSTMENT>'."\n";
        $xml .= '            <VCHSTATUSVOUCHERTYPE>Journal</VCHSTATUSVOUCHERTYPE>'."\n";
        $xml .= '            <VCHGSTCLASS>&#4; Not Applicable</VCHGSTCLASS>'."\n";
        $xml .= '            <VCHENTRYYMODE>As Voucher</VCHENTRYYMODE>'."\n";
        $xml .= '            <EFFECTIVEDATE>'.$date.'</EFFECTIVEDATE>'."\n";
        $xml .= $this->journalVoucherBooleanFlags();
        $xml .= $this->preLedgerEmptyLists();
        $xml .= $this->generateBankJournalLedgerEntries($headName, $bankName, $amount, $isDebit);
        $xml .= $this->postVoucherEmptyLists();
        $xml .= '          </VOUCHER>'."\n";
        $xml .= '        </TALLYMESSAGE>'."\n";

        return $xml;
    }

    /**
     * Generate a Journal voucher for an invoice transaction with GST breakup.
     * Multi-leg: expense debit, CGST/SGST (or IGST) debits, TDS credit (optional), vendor party credit.
     *
     * @param  array<string, mixed>  $raw  Pre-decrypted raw_data for the transaction
     */
    private function generateInvoiceJournalVoucher(Transaction $transaction, ?Company $company, array $raw): string
    {
        /** @var Carbon $transactionDate */
        $transactionDate = $transaction->date;
        $date = $transactionDate->format('Ymd');
        /** @var AccountHead|null $accountHead */
        $accountHead = $transaction->accountHead;
        $headName = $accountHead?->name ?? 'Unknown';
        $voucherNumber = $this->nextVoucherNumber('Journal');
        $vendorName = (string) ($raw['vendor_name'] ?? 'Unknown Vendor');
        $vendorGstin = (string) ($raw['vendor_gstin'] ?? '');
        $invoiceNumber = (string) ($raw['invoice_number'] ?? '');
        $baseAmount = (float) ($raw['base_amount'] ?? 0);
        $cgstRate = $raw['cgst_rate'] ?? null;
        $cgstAmount = (float) ($raw['cgst_amount'] ?? 0);
        $sgstRate = $raw['sgst_rate'] ?? null;
        $sgstAmount = (float) ($raw['sgst_amount'] ?? 0);
        $igstRate = $raw['igst_rate'] ?? null;
        $igstAmount = (float) ($raw['igst_amount'] ?? 0);
        $tdsAmount = (float) ($raw['tds_amount'] ?? 0);
        $totalAmount = (float) ($raw['total_amount'] ?? (float) ($transaction->debit ?? 0));

        $narration = $invoiceNumber
            ? "Invoice No: {$invoiceNumber} payment towards {$vendorName}"
            : "Invoice payment towards {$vendorName}";

        $lineItemNarration = $this->buildLineItemNarration($raw);

        if ($lineItemNarration !== null) {
            $narration .= "\n{$lineItemNarration}";
        }

        $xml = '        <TALLYMESSAGE xmlns:UDF="TallyUDF">'."\n";
        $xml .= '          <VOUCHER VCHTYPE="Journal" ACTION="Create" OBJVIEW="Accounting Voucher View">'."\n";
        $xml .= '            <DATE>'.$date.'</DATE>'."\n";
        $xml .= '            <VCHSTATUSDATE>'.$date.'</VCHSTATUSDATE>'."\n";
        $xml .= '            <NARRATION>'.$this->escapeXml($narration).'</NARRATION>'."\n";
        $xml .= '            <VOUCHERTYPENAME>Journal</VOUCHERTYPENAME>'."\n";
        $xml .= '            <VOUCHERNUMBER>'.$voucherNumber.'</VOUCHERNUMBER>'."\n";
        $xml .= '            <PARTYLEDGERNAME>'.$this->escapeXml($vendorName).'</PARTYLEDGERNAME>'."\n";
        $xml .= '            <GSTREGISTRATIONTYPE>Regular</GSTREGISTRATIONTYPE>'."\n";

        if ($vendorGstin !== '') {
            $xml .= '            <PARTYGSTIN>'.$this->escapeXml($vendorGstin).'</PARTYGSTIN>'."\n";
        }

        $xml .= $this->journalVoucherGstFields($company);
        $xml .= '            <EFFECTIVEDATE>'.$date.'</EFFECTIVEDATE>'."\n";
        $xml .= '            <ISDELETED>No</ISDELETED>'."\n";
        $xml .= '            <ISCANCELLED>No</ISCANCELLED>'."\n";
        $xml .= '            <ISONHOLD>No</ISONHOLD>'."\n";
        $xml .= '            <ISOPTIONAL>No</ISOPTIONAL>'."\n";
        $xml .= '            <AUDITED>No</AUDITED>'."\n";

        $xml .= $this->generateExpenseLedgerEntry($headName, $baseAmount, $cgstRate, $sgstRate);
        $xml .= $this->generateGstLedgerEntries($igstRate, $igstAmount, $cgstRate, $cgstAmount, $sgstRate, $sgstAmount, $company);

        if ($tdsAmount > 0) {
            $tdsLedger = $company?->tally_tds_payable_ledger ?? 'TDS Payable';
            $xml .= '            <ALLLEDGERENTRIES.LIST>'."\n";
            $xml .= '              <LEDGERNAME>'.$this->escapeXml($tdsLedger).'</LEDGERNAME>'."\n";
            $xml .= '              <ISDEEMEDPOSITIVE>No</ISDEEMEDPOSITIVE>'."\n";
            $xml .= '              <AMOUNT>'.number_format($tdsAmount, 2, '.', '').'</AMOUNT>'."\n";
            $xml .= '            </ALLLEDGERENTRIES.LIST>'."\n";
        }

        $partyAmount = $tdsAmount > 0 ? $totalAmount - $tdsAmount : $totalAmount;
        $xml .= '            <ALLLEDGERENTRIES.LIST>'."\n";
        $xml .= '              <LEDGERNAME>'.$this->escapeXml($vendorName).'</LEDGERNAME>'."\n";
        $xml .= '              <ISPARTYLEDGER>Yes</ISPARTYLEDGER>'."\n";
        $xml .= '              <ISDEEMEDPOSITIVE>No</ISDEEMEDPOSITIVE>'."\n";
        $xml .= '              <AMOUNT>'.number_format($partyAmount, 2, '.', '').'</AMOUNT>'."\n";
        $xml .= '            </ALLLEDGERENTRIES.LIST>'."\n";

        $xml .= '          </VOUCHER>'."\n";
        $xml .= '        </TALLYMESSAGE>'."\n";

        return $xml;
    }

    /**
     * Generate a Sales voucher for an outward (sales) invoice.
     * Multi-leg: customer party debit, sales revenue credit, Output GST credits.
     *
     * @param  array<string, mixed>  $raw  Pre-decrypted raw_data for the transaction
     */
    private function generateSalesVoucher(Transaction $transaction, ?Company $company, array $raw): string
    {
        /** @var Carbon $transactionDate */
        $transactionDate = $transaction->date;

        $invoiceDateRaw = (string) ($raw['invoice_date'] ?? '');
        $date = $invoiceDateRaw !== ''
            ? Carbon::parse($invoiceDateRaw)->format('Ymd')
            : $transactionDate->format('Ymd');

        /** @var AccountHead|null $accountHead */
        $accountHead = $transaction->accountHead;
        $voucherNumber = (string) ($raw['invoice_number'] ?? $this->nextVoucherNumber('Sales'));

        $buyerName = (string) ($raw['buyer_name'] ?? 'Unknown Buyer');
        $buyerGstin = (string) ($raw['buyer_gstin'] ?? '');
        $placeOfSupply = (string) ($raw['place_of_supply'] ?? '');
        $serviceName = (string) ($raw['service_name'] ?? $this->deriveServiceName($raw) ?? ($accountHead?->name ?? 'Unknown'));
        $hsnSac = (string) ($raw['hsn_sac'] ?? '');
        $narration = $this->buildLineItemNarration($raw, $serviceName)
            ?? $this->stripServicePrefix((string) ($raw['description'] ?? $transaction->description ?? ''), $serviceName);
        $baseAmount = (float) ($raw['base_amount'] ?? 0);
        $cgstRate = $raw['cgst_rate'] ?? null;
        $cgstAmount = (float) ($raw['cgst_amount'] ?? 0);
        $sgstRate = $raw['sgst_rate'] ?? null;
        $sgstAmount = (float) ($raw['sgst_amount'] ?? 0);
        $igstRate = $raw['igst_rate'] ?? null;
        $igstAmount = (float) ($raw['igst_amount'] ?? 0);

        /** @var array<int, string> $buyerAddress */
        $buyerAddress = is_array($raw['buyer_address'] ?? null) ? $raw['buyer_address'] : [];

        $hasIgst = $igstRate !== null && $igstAmount > 0;
        $partyAmount = $hasIgst
            ? $baseAmount + $igstAmount
            : $baseAmount + $cgstAmount + $sgstAmount;

        $xml = '        <TALLYMESSAGE xmlns:UDF="TallyUDF">'."\n";
        $xml .= '          <VOUCHER VCHTYPE="Sales" ACTION="Create" OBJVIEW="Invoice Voucher View">'."\n";
        $xml .= '            <DATE>'.$date.'</DATE>'."\n";
        $xml .= '            <NARRATION>'.$this->escapeXml($narration).'</NARRATION>'."\n";
        $xml .= '            <VOUCHERTYPENAME>Sales</VOUCHERTYPENAME>'."\n";
        $xml .= '            <VOUCHERNUMBER>'.$this->escapeXml($voucherNumber).'</VOUCHERNUMBER>'."\n";
        $xml .= '            <PARTYLEDGERNAME>'.$this->escapeXml($buyerName).'</PARTYLEDGERNAME>'."\n";
        $xml .= '            <PARTYMAILINGNAME>'.$this->escapeXml($buyerName).'</PARTYMAILINGNAME>'."\n";

        if ($buyerGstin !== '') {
            $xml .= '            <PARTYGSTIN>'.$this->escapeXml($buyerGstin).'</PARTYGSTIN>'."\n";
        }

        $xml .= $this->journalVoucherGstFields($company);
        $xml .= '            <GSTREGISTRATIONTYPE>Regular</GSTREGISTRATIONTYPE>'."\n";

        if ($placeOfSupply !== '') {
            $xml .= '            <STATENAME>'.$this->escapeXml($placeOfSupply).'</STATENAME>'."\n";
            $xml .= '            <PLACEOFSUPPLY>'.$this->escapeXml($placeOfSupply).'</PLACEOFSUPPLY>'."\n";
        }

        $xml .= '            <ISINVOICE>Yes</ISINVOICE>'."\n";
        $xml .= '            <VCHENTRYMORE>Accounting Invoice</VCHENTRYMORE>'."\n";
        $xml .= '            <NUMBERINGSTYLE>Manual</NUMBERINGSTYLE>'."\n";
        $xml .= '            <EFFECTIVEDATE>'.$date.'</EFFECTIVEDATE>'."\n";
        $xml .= '            <ISREVERSCHARGEAPPLICABLE>No</ISREVERSCHARGEAPPLICABLE>'."\n";
        $xml .= '            <ISELIGIBLEFORLITC>Yes</ISELIGIBLEFORLITC>'."\n";

        if ($buyerAddress !== []) {
            $xml .= '            <ADDRESS.LIST TYPE="String">'."\n";
            foreach ($buyerAddress as $line) {
                $xml .= '              <ADDRESS>'.$this->escapeXml($line).'</ADDRESS>'."\n";
            }
            $xml .= '            </ADDRESS.LIST>'."\n";
        }

        $xml .= '            <LEDGERENTRIES.LIST>'."\n";
        $xml .= '              <LEDGERNAME>'.$this->escapeXml($buyerName).'</LEDGERNAME>'."\n";
        $xml .= '              <ISDEEMEDPOSITIVE>Yes</ISDEEMEDPOSITIVE>'."\n";
        $xml .= '              <ISPARTYLEDGER>Yes</ISPARTYLEDGER>'."\n";
        $xml .= '              <AMOUNT>-'.number_format($partyAmount, 2, '.', '').'</AMOUNT>'."\n";
        $xml .= '            </LEDGERENTRIES.LIST>'."\n";

        $xml .= '            <LEDGERENTRIES.LIST>'."\n";
        $xml .= '              <LEDGERNAME>'.$this->escapeXml($serviceName).'</LEDGERNAME>'."\n";
        $xml .= '              <ISDEEMEDPOSITIVE>No</ISDEEMEDPOSITIVE>'."\n";
        $xml .= '              <ISPARTYLEDGER>No</ISPARTYLEDGER>'."\n";
        $xml .= '              <AMOUNT>'.number_format($baseAmount, 2, '.', '').'</AMOUNT>'."\n";

        if ($hsnSac !== '') {
            $xml .= '              <GSTHSNNAME>'.$this->escapeXml($hsnSac).'</GSTHSNNAME>'."\n";
        }

        $xml .= '              <GSTOVRDNTAXABILITY>Taxable</GSTOVRDNTAXABILITY>'."\n";
        $xml .= '              <GSTOVRDNTYPEOFSUPPLY>Services</GSTOVRDNTYPEOFSUPPLY>'."\n";

        if ($hasIgst) {
            $xml .= '              <RATEDETAILS.LIST>'."\n";
            $xml .= '                <GSTRATEDUTYHEAD>IGST</GSTRATEDUTYHEAD>'."\n";
            $xml .= '                <GSTRATEVALUATIONTYPE>Based on Value</GSTRATEVALUATIONTYPE>'."\n";
            $xml .= '                <GSTRATE>'.$igstRate.'</GSTRATE>'."\n";
            $xml .= '              </RATEDETAILS.LIST>'."\n";
        } elseif ($cgstRate !== null && $sgstRate !== null) {
            $xml .= '              <RATEDETAILS.LIST>'."\n";
            $xml .= '                <GSTRATEDUTYHEAD>CGST</GSTRATEDUTYHEAD>'."\n";
            $xml .= '                <GSTRATEVALUATIONTYPE>Based on Value</GSTRATEVALUATIONTYPE>'."\n";
            $xml .= '                <GSTRATE>'.$cgstRate.'</GSTRATE>'."\n";
            $xml .= '              </RATEDETAILS.LIST>'."\n";
            $xml .= '              <RATEDETAILS.LIST>'."\n";
            $xml .= '                <GSTRATEDUTYHEAD>SGST/UTGST</GSTRATEDUTYHEAD>'."\n";
            $xml .= '                <GSTRATEVALUATIONTYPE>Based on Value</GSTRATEVALUATIONTYPE>'."\n";
            $xml .= '                <GSTRATE>'.$sgstRate.'</GSTRATE>'."\n";
            $xml .= '              </RATEDETAILS.LIST>'."\n";
        }

        $xml .= '            </LEDGERENTRIES.LIST>'."\n";

        if ($hasIgst) {
            $xml .= $this->generateOutputTaxLedgerEntry(
                $this->resolveRateLedgerName($company, 'tally_output_igst_ledger', 'Output Igst @ {rate}%', $igstRate),
                $igstRate,
                $igstAmount,
            );
        } else {
            if ($cgstRate !== null && $cgstAmount > 0) {
                $xml .= $this->generateOutputTaxLedgerEntry(
                    $this->resolveRateLedgerName($company, 'tally_output_cgst_ledger', 'Output Cgst @ {rate}%', $cgstRate),
                    $cgstRate,
                    $cgstAmount,
                );
            }

            if ($sgstRate !== null && $sgstAmount > 0) {
                $xml .= $this->generateOutputTaxLedgerEntry(
                    $this->resolveRateLedgerName($company, 'tally_output_sgst_ledger', 'Output Sgst @ {rate}%', $sgstRate),
                    $sgstRate,
                    $sgstAmount,
                );
            }
        }

        $xml .= '          </VOUCHER>'."\n";
        $xml .= '        </TALLYMESSAGE>'."\n";

        return $xml;
    }

    private function generateOutputTaxLedgerEntry(string $ledgerName, float|int $rate, float $amount): string
    {
        $xml = '            <LEDGERENTRIES.LIST>'."\n";
        $xml .= '              <LEDGERNAME>'.$this->escapeXml($ledgerName).'</LEDGERNAME>'."\n";
        $xml .= '              <ISDEEMEDPOSITIVE>No</ISDEEMEDPOSITIVE>'."\n";
        $xml .= '              <ISPARTYLEDGER>No</ISPARTYLEDGER>'."\n";
        $xml .= '              <RATEOFINVOICETAX.LIST TYPE="Number">'."\n";
        $xml .= '                <RATEOFINVOICETAX>'.$rate.'</RATEOFINVOICETAX>'."\n";
        $xml .= '              </RATEOFINVOICETAX.LIST>'."\n";
        $xml .= '              <AMOUNT>'.number_format($amount, 2, '.', '').'</AMOUNT>'."\n";
        $xml .= '            </LEDGERENTRIES.LIST>'."\n";

        return $xml;
    }

    private function generateExpenseLedgerEntry(string $headName, float $baseAmount, mixed $cgstRate, mixed $sgstRate): string
    {
        $xml = '            <ALLLEDGERENTRIES.LIST>'."\n";
        $xml .= '              <LEDGERNAME>'.$this->escapeXml($headName).'</LEDGERNAME>'."\n";
        $xml .= '              <ISDEEMEDPOSITIVE>Yes</ISDEEMEDPOSITIVE>'."\n";
        $xml .= '              <AMOUNT>-'.number_format($baseAmount, 2, '.', '').'</AMOUNT>'."\n";

        if ($cgstRate !== null && $sgstRate !== null) {
            $xml .= '              <RATEDETAILS.LIST>'."\n";
            $xml .= '                <GSTRATEDUTYHEAD>CGST</GSTRATEDUTYHEAD>'."\n";
            $xml .= '                <GSTRATEVALUATIONTYPE>Based on Value</GSTRATEVALUATIONTYPE>'."\n";
            $xml .= '              </RATEDETAILS.LIST>'."\n";
            $xml .= '              <RATEDETAILS.LIST>'."\n";
            $xml .= '                <GSTRATEDUTYHEAD>SGST/UTGST</GSTRATEDUTYHEAD>'."\n";
            $xml .= '                <GSTRATEVALUATIONTYPE>Based on Value</GSTRATEVALUATIONTYPE>'."\n";
            $xml .= '              </RATEDETAILS.LIST>'."\n";
        }

        $xml .= '            </ALLLEDGERENTRIES.LIST>'."\n";

        return $xml;
    }

    private function generateGstLedgerEntries(mixed $igstRate, float $igstAmount, mixed $cgstRate, float $cgstAmount, mixed $sgstRate, float $sgstAmount, ?Company $company): string
    {
        if ($igstRate !== null && $igstAmount > 0) {
            return $this->generateTaxLedgerEntry(
                $this->resolveRateLedgerName($company, 'tally_input_igst_ledger', 'Input Igst @ {rate}%', $igstRate),
                $igstAmount,
            );
        }

        $xml = '';

        if ($cgstRate !== null && $cgstAmount > 0) {
            $xml .= $this->generateTaxLedgerEntry(
                $this->resolveRateLedgerName($company, 'tally_input_cgst_ledger', 'Input Cgst @ {rate}%', $cgstRate),
                $cgstAmount,
            );
        }

        if ($sgstRate !== null && $sgstAmount > 0) {
            $xml .= $this->generateTaxLedgerEntry(
                $this->resolveRateLedgerName($company, 'tally_input_sgst_ledger', 'Input Sgst @ {rate}%', $sgstRate),
                $sgstAmount,
            );
        }

        return $xml;
    }

    private function generateTaxLedgerEntry(string $ledgerName, float $amount): string
    {
        $xml = '            <ALLLEDGERENTRIES.LIST>'."\n";
        $xml .= '              <LEDGERNAME>'.$this->escapeXml($ledgerName).'</LEDGERNAME>'."\n";
        $xml .= '              <ISDEEMEDPOSITIVE>Yes</ISDEEMEDPOSITIVE>'."\n";
        $xml .= '              <AMOUNT>-'.number_format($amount, 2, '.', '').'</AMOUNT>'."\n";
        $xml .= '            </ALLLEDGERENTRIES.LIST>'."\n";

        return $xml;
    }

    /**
     * Generate the two-leg journal entry for a bank/CC transaction.
     * BY (debit, ISDEEMEDPOSITIVE=Yes): account head mapped by the user.
     * TO (credit, ISDEEMEDPOSITIVE=No): bank/CC card ledger name.
     */
    private function generateBankJournalLedgerEntries(string $headName, string $bankName, float $amount, bool $isDebit): string
    {
        $formattedAmount = number_format($amount, 2, '.', '');

        [$debitLedger, $creditLedger] = $isDebit
            ? [$headName, $bankName]
            : [$bankName, $headName];

        return $this->bankJournalLedgerEntry($debitLedger, true, $formattedAmount)
            .$this->bankJournalLedgerEntry($creditLedger, false, $formattedAmount);
    }

    private function bankJournalLedgerEntry(string $ledgerName, bool $isDeemedPositive, string $formattedAmount): string
    {
        $pos = $isDeemedPositive ? 'Yes' : 'No';
        $amount = $isDeemedPositive ? "-{$formattedAmount}" : $formattedAmount;
        $p = '              ';

        $xml = '            <ALLLEDGERENTRIES.LIST>'."\n";
        $xml .= $p.'<OLDAUDITENTRYIDS.LIST TYPE="Number">'."\n";
        $xml .= "                <OLDAUDITENTRYIDS>-1</OLDAUDITENTRYIDS>\n";
        $xml .= $p.'</OLDAUDITENTRYIDS.LIST>'."\n";
        $xml .= $p.'<LEDGERNAME>'.$this->escapeXml($ledgerName).'</LEDGERNAME>'."\n";
        $xml .= $p.'<GSTCLASS>&#4; Not Applicable</GSTCLASS>'."\n";
        $xml .= $p.'<ISDEEMEDPOSITIVE>'.$pos.'</ISDEEMEDPOSITIVE>'."\n";
        $xml .= $p.'<LEDGERFROMITEM>No</LEDGERFROMITEM>'."\n";
        $xml .= $p.'<REMOVEZEROENTRIES>No</REMOVEZEROENTRIES>'."\n";
        $xml .= $p.'<ISPARTYLEDGER>Yes</ISPARTYLEDGER>'."\n";
        $xml .= $p.'<GSTOVERRIDDEN>No</GSTOVERRIDDEN>'."\n";
        $xml .= $p.'<ISGSTASSESSABLEVALUEOVERRIDDEN>No</ISGSTASSESSABLEVALUEOVERRIDDEN>'."\n";
        $xml .= $p.'<STRDISGSTAPPLICABLE>No</STRDISGSTAPPLICABLE>'."\n";
        $xml .= $p.'<STRDGSTISPARTYLEDGER>No</STRDGSTISPARTYLEDGER>'."\n";
        $xml .= $p.'<STRDGSTISDUTYLEDGER>No</STRDGSTISDUTYLEDGER>'."\n";
        $xml .= $p.'<CONTENTNEGISPOS>No</CONTENTNEGISPOS>'."\n";
        $xml .= $p.'<ISLASTDEEMEDPOSITIVE>'.$pos.'</ISLASTDEEMEDPOSITIVE>'."\n";
        $xml .= $p.'<ISCAPVATTAXALTERED>No</ISCAPVATTAXALTERED>'."\n";
        $xml .= $p.'<ISCAPVATNOTCLAIMED>No</ISCAPVATNOTCLAIMED>'."\n";
        $xml .= $p.'<AMOUNT>'.$amount.'</AMOUNT>'."\n";
        $xml .= $p.'<VATEXPAMOUNT>'.$amount.'</VATEXPAMOUNT>'."\n";
        $xml .= $this->buildEmptyXmlLists($p, [
            'SERVICETAXDETAILS', 'BANKALLOCATIONS', 'BILLALLOCATIONS',
            'INTERESTCOLLECTION', 'OLDAUDITENTRIES', 'ACCOUNTAUDITENTRIES',
            'AUDITENTRIES', 'INPUTCRALLOCS', 'DUTYHEADDETAILS',
            'EXCISEDUTYHEADDETAILS', 'RATEDETAILS', 'SUMMARYALLOCS',
            'CENVATDUTYALLOCATIONS', 'STPYMTDETAILS', 'EXCISEPAYMENTALLOCATIONS',
            'TAXBILLALLOCATIONS', 'TAXOBJECTALLOCATIONS', 'TDSEXPENSEALLOCATIONS',
            'VATSTATUTORYDETAILS', 'COSTTRACKALLOCATIONS', 'REFVOUCHERDETAILS',
            'INVOICEWISEDETAILS', 'VATITCDETAILS', 'ADVANCETAXDETAILS',
            'TAXTYPEALLOCATIONS',
        ]);
        $xml .= '            </ALLLEDGERENTRIES.LIST>'."\n";

        return $xml;
    }

    /**
     * Generate the company identity footer block.
     * Two REMOTECMPINFO.LIST entries: one keyed by company name, one by GSTIN.
     */
    private function generateCompanyFooter(Company $company): string
    {
        $name = $this->escapeXml($company->name ?? '');
        $gstin = $this->escapeXml($company->gstin ?? '');
        $state = $this->escapeXml($company->state ?? '');

        $xml = '        <TALLYMESSAGE xmlns:UDF="TallyUDF">'."\n";
        $xml .= '          <COMPANY>'."\n";
        $xml .= '            <REMOTECMPINFO.LIST MERGE="Yes">'."\n";
        $xml .= '              <NAME>'.$name.'</NAME>'."\n";
        $xml .= '              <REMOTECMPNAME>'.$name.'</REMOTECMPNAME>'."\n";
        $xml .= '              <REMOTECMPSTATE>'.$state.'</REMOTECMPSTATE>'."\n";
        $xml .= '            </REMOTECMPINFO.LIST>'."\n";
        $xml .= '            <REMOTECMPINFO.LIST MERGE="Yes">'."\n";
        $xml .= '              <NAME>'.$gstin.'</NAME>'."\n";
        $xml .= '              <REMOTECMPNAME>'.$name.'</REMOTECMPNAME>'."\n";
        $xml .= '              <REMOTECMPSTATE>'.$state.'</REMOTECMPSTATE>'."\n";
        $xml .= '            </REMOTECMPINFO.LIST>'."\n";
        $xml .= '          </COMPANY>'."\n";
        $xml .= '        </TALLYMESSAGE>'."\n";

        return $xml;
    }

    /**
     * Only purchase invoice Journal exports use REPORTNAME=All Masters.
     * Sales invoices and bank/CC journal exports both use Vouchers.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @param  array<string, mixed>|null  $firstRaw  Pre-decrypted raw_data for the first transaction (avoids redundant decryption)
     */
    private function isAllMastersExport(Collection $transactions, ?ImportedFile $importedFile, ?array $firstRaw = null): bool
    {
        if ($importedFile?->statement_type !== StatementType::Invoice) {
            return false;
        }

        /** @var Transaction|null $first */
        $first = $transactions->first();

        if ($first === null) {
            return false;
        }

        /** @var array<string, mixed> $raw */
        $raw = $firstRaw ?? ($first->raw_data ?? []);

        return ! isset($raw['buyer_name']);
    }

    /**
     * Get the next sequential voucher number for a given type.
     */
    private function nextVoucherNumber(string $voucherType): int
    {
        if (! isset($this->voucherCounters[$voucherType])) {
            $this->voucherCounters[$voucherType] = 0;
        }

        return ++$this->voucherCounters[$voucherType];
    }

    /** @param array<string, mixed> $raw */
    private function deriveServiceName(array $raw): ?string
    {
        $lineItems = $this->getLineItems($raw);

        if (empty($lineItems)) {
            return null;
        }

        $firstDesc = (string) ($lineItems[0]['description'] ?? '');

        if (! str_contains($firstDesc, ' - ')) {
            return null;
        }

        return trim(explode(' - ', $firstDesc, 2)[0]);
    }

    /** @param array<string, mixed> $raw */
    private function buildLineItemNarration(array $raw, ?string $serviceName = null): ?string
    {
        $lineItems = $this->getLineItems($raw);

        if (empty($lineItems)) {
            return null;
        }

        $descriptions = array_filter(array_column($lineItems, 'description'));

        if ($serviceName !== null) {
            $descriptions = array_map(
                fn (string $desc) => $this->stripServicePrefix($desc, $serviceName),
                $descriptions,
            );
        }

        return empty($descriptions) ? null : implode("\n", $descriptions);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<int, mixed>
     */
    private function getLineItems(array $raw): array
    {
        return is_array($raw['line_items'] ?? null) ? $raw['line_items'] : [];
    }

    private function stripServicePrefix(string $value, string $serviceName): string
    {
        if ($serviceName === '' || ! str_starts_with($value, $serviceName)) {
            return $value;
        }

        $remainder = mb_substr($value, mb_strlen($serviceName));

        // Only strip when the service name ends at a word boundary (not mid-word)
        if ($remainder === '' || ctype_alnum(mb_substr($remainder, 0, 1))) {
            return $value;
        }

        return $this->unwrapParens(ltrim($remainder, ' -:'));
    }

    private function unwrapParens(string $value): string
    {
        return preg_match('/^\(([^()]+)\)$/', $value, $m) ? $m[1] : $value;
    }

    private function journalVoucherGstFields(?Company $company): string
    {
        if (! $company) {
            return '';
        }

        $xml = '            <CMPGSTIN>'.$this->escapeXml($company->gstin ?? '').'</CMPGSTIN>'."\n";
        $xml .= '            <CMPGSTREGISTRATIONTYPE>'.$this->escapeXml($company->gst_registration_type ?? 'Regular').'</CMPGSTREGISTRATIONTYPE>'."\n";
        $xml .= '            <CMPGSTSTATE>'.$this->escapeXml($company->state ?? '').'</CMPGSTSTATE>'."\n";

        return $xml;
    }

    private function journalVoucherBooleanFlags(): string
    {
        $xml = '';
        foreach (self::JOURNAL_BOOLEAN_FLAGS as $flag => $value) {
            $xml .= "            <{$flag}>{$value}</{$flag}>\n";
        }

        return $xml;
    }

    private function preLedgerEmptyLists(): string
    {
        return $this->buildEmptyXmlLists('            ', [
            'EWAYBILLDETAILS', 'EXCLUDEDTAXATIONS', 'OLDAUDITENTRIES',
            'ACCOUNTAUDITENTRIES', 'AUDITENTRIES', 'DUTYHEADDETAILS',
            'GSTADVADJDETAILS', 'CONTRITRANS', 'EWAYBILLERRORLIST',
            'IRNERRORLIST', 'HARYANAVAT', 'SUPPLEMENTARYDUTYHEADDETAILS',
            'INVOICEDELNOTES', 'INVOICEORDERLIST', 'INVOICEINDENTLIST',
            'ATTENDANCEENTRIES', 'ORIGINIVOICEDETAILS', 'INVOICEEXPORTLIST',
        ]);
    }

    private function postVoucherEmptyLists(): string
    {
        return $this->buildEmptyXmlLists('            ', [
            'GST', 'STKJRNLADDLCOSTDETAILS', 'GSTBUYERADDRESS',
            'GSTCONSIGNEEADDRESS', 'PAYROLLMODEOFPAYMENT', 'ATTDRECORDS',
            'GSTEWAYCONSIGNORADDRESS', 'GSTEWAYCONSIGNEEADDRESS',
            'TEMPGSTRATEDETAILS', 'TEMPGSTADVADJUSTED',
        ]);
    }

    /** @param string[] $tags */
    private function buildEmptyXmlLists(string $indent, array $tags): string
    {
        $xml = '';
        foreach ($tags as $tag) {
            $xml .= "{$indent}<{$tag}.LIST>             </{$tag}.LIST>\n";
        }

        return $xml;
    }

    private function resolveRateLedgerName(?Company $company, string $field, string $default, mixed $rate): string
    {
        $template = $company?->$field ?? $default;

        return str_replace('{rate}', (string) $rate, $template);
    }

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
