<?php

use App\Ai\Agents\InvoiceParser;
use App\Enums\StatementType;
use App\Models\AccountHead;
use App\Models\ImportedFile;
use App\Models\Transaction;
use App\Services\TallyExport\TallyExportService;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;

describe('TallyExportService sales voucher', function () {
    beforeEach(fn () => asUser());

    it('exports sales invoice as Sales voucher not Journal', function () {
        $file = ImportedFile::factory()->create([
            'statement_type' => StatementType::Invoice,
            'company_id' => tenant()->id,
        ]);
        $head = AccountHead::factory()->create(['company_id' => tenant()->id]);
        Transaction::factory()->mapped($head)->create([
            'imported_file_id' => $file->id,
            'company_id' => tenant()->id,
            'credit' => '3844.44',
            'date' => '2026-04-01',
            'raw_data' => [
                'buyer_name' => 'VYGNIK BEHAVIORAL SERVICES PRIVATE LIMITED',
                'buyer_gstin' => '29AAGCV9545C1ZZ',
                'buyer_address' => ['NO 10 & 11 NAT STREET BASAVANAGUDI'],
                'place_of_supply' => 'Karnataka',
                'service_name' => 'Website Maintenance',
                'hsn_sac' => '998313',
                'invoice_number' => 'ZY26-0002',
                'invoice_date' => '2026-04-01',
                'base_amount' => 3258.00,
                'cgst_rate' => 9,
                'cgst_amount' => 293.22,
                'sgst_rate' => 9,
                'sgst_amount' => 293.22,
                'igst_rate' => null,
                'igst_amount' => null,
                'total_amount' => 3844.44,
            ],
        ]);

        $xml = app(TallyExportService::class)->exportForFile($file);

        expect($xml)
            ->toContain('VCHTYPE="Sales"')
            ->toContain('<PERSISTEDVIEW>Accounting Voucher View</PERSISTEDVIEW>')
            ->not->toContain('VCHTYPE="Journal"');
    });

    it('uses REPORTNAME Vouchers for sales invoice export', function () {
        $file = ImportedFile::factory()->create([
            'statement_type' => StatementType::Invoice,
            'company_id' => tenant()->id,
        ]);
        $head = AccountHead::factory()->create(['company_id' => tenant()->id]);
        Transaction::factory()->mapped($head)->create([
            'imported_file_id' => $file->id,
            'company_id' => tenant()->id,
            'credit' => '3844.44',
            'date' => '2026-04-01',
            'raw_data' => [
                'buyer_name' => 'Test Client Pvt Ltd',
                'service_name' => 'IT Services',
                'base_amount' => 3258.00,
                'cgst_rate' => 9,
                'cgst_amount' => 293.22,
                'sgst_rate' => 9,
                'sgst_amount' => 293.22,
                'total_amount' => 3844.44,
            ],
        ]);

        $xml = app(TallyExportService::class)->exportForFile($file);

        expect($xml)
            ->toContain('<REPORTNAME>Vouchers</REPORTNAME>')
            ->not->toContain('<REPORTNAME>All Masters</REPORTNAME>');
    });

    it('generates intrastate 4-leg voucher: party debit, sales credit, CGST credit, SGST credit', function () {
        $file = ImportedFile::factory()->create([
            'statement_type' => StatementType::Invoice,
            'company_id' => tenant()->id,
        ]);
        $head = AccountHead::factory()->create([
            'company_id' => tenant()->id,
            'name' => 'Website Maintenance',
        ]);
        Transaction::factory()->mapped($head)->create([
            'imported_file_id' => $file->id,
            'company_id' => tenant()->id,
            'credit' => '3844.44',
            'date' => '2026-04-01',
            'raw_data' => [
                'buyer_name' => 'VYGNIK BEHAVIORAL SERVICES PRIVATE LIMITED',
                'service_name' => 'Website Maintenance',
                'base_amount' => 3258.00,
                'cgst_rate' => 9,
                'cgst_amount' => 293.22,
                'sgst_rate' => 9,
                'sgst_amount' => 293.22,
                'igst_rate' => null,
                'igst_amount' => null,
                'total_amount' => 3844.44,
            ],
        ]);

        $xml = app(TallyExportService::class)->exportForFile($file);

        // Party debit leg
        expect($xml)
            ->toContain('<ISDEEMEDPOSITIVE>Yes</ISDEEMEDPOSITIVE>')
            ->toContain('<ISPARTYLEDGER>Yes</ISPARTYLEDGER>')
            ->toContain('-3844.44')
            // Sales credit leg
            ->toContain('Website Maintenance')
            ->toContain('3258.00')
            // Tax credit legs
            ->toContain('Output Cgst @ 9%')
            ->toContain('Output Sgst @ 9%')
            ->toContain('293.22')
            // No IGST
            ->not->toContain('Output Igst');
    });

    it('generates interstate 3-leg voucher: party debit, sales credit, IGST credit', function () {
        $file = ImportedFile::factory()->create([
            'statement_type' => StatementType::Invoice,
            'company_id' => tenant()->id,
        ]);
        $head = AccountHead::factory()->create([
            'company_id' => tenant()->id,
            'name' => 'IT Consulting',
        ]);
        Transaction::factory()->mapped($head)->create([
            'imported_file_id' => $file->id,
            'company_id' => tenant()->id,
            'credit' => '11800.00',
            'date' => '2026-04-01',
            'raw_data' => [
                'buyer_name' => 'Mumbai Client Pvt Ltd',
                'place_of_supply' => 'Maharashtra',
                'service_name' => 'IT Consulting',
                'base_amount' => 10000.00,
                'cgst_rate' => null,
                'cgst_amount' => null,
                'sgst_rate' => null,
                'sgst_amount' => null,
                'igst_rate' => 18,
                'igst_amount' => 1800.00,
                'total_amount' => 11800.00,
            ],
        ]);

        $xml = app(TallyExportService::class)->exportForFile($file);

        expect($xml)
            ->toContain('Output Igst @ 18%')
            ->toContain('-11800.00')
            ->toContain('10000.00')
            ->toContain('1800.00')
            ->not->toContain('Output Cgst')
            ->not->toContain('Output Sgst');
    });

    it('sets party ledger amount to exact mathematical sum with no rounding entry', function () {
        $file = ImportedFile::factory()->create([
            'statement_type' => StatementType::Invoice,
            'company_id' => tenant()->id,
        ]);
        $head = AccountHead::factory()->create(['company_id' => tenant()->id]);
        Transaction::factory()->mapped($head)->create([
            'imported_file_id' => $file->id,
            'company_id' => tenant()->id,
            'credit' => '3844.44',
            'date' => '2026-04-01',
            'raw_data' => [
                'buyer_name' => 'VYGNIK BEHAVIORAL SERVICES PRIVATE LIMITED',
                'service_name' => 'Website Maintenance',
                'base_amount' => 3258.00,
                'cgst_rate' => 9,
                'cgst_amount' => 293.22,
                'sgst_rate' => 9,
                'sgst_amount' => 293.22,
                'total_amount' => 3844.44,
            ],
        ]);

        $xml = app(TallyExportService::class)->exportForFile($file);

        // Party amount = -(base + cgst + sgst) = -(3258 + 293.22 + 293.22) = -3844.44
        expect($xml)
            ->toContain('-3844.44')
            ->not->toContain('Rounding');
    });

    it('includes GSTHSNNAME, GSTOVRDNTAXABILITY and GSTOVRDNTYPEOFSUPPLY in sales ledger entry', function () {
        $file = ImportedFile::factory()->create([
            'statement_type' => StatementType::Invoice,
            'company_id' => tenant()->id,
        ]);
        $head = AccountHead::factory()->create([
            'company_id' => tenant()->id,
            'name' => 'Website Maintenance',
        ]);
        Transaction::factory()->mapped($head)->create([
            'imported_file_id' => $file->id,
            'company_id' => tenant()->id,
            'credit' => '3844.44',
            'date' => '2026-04-01',
            'raw_data' => [
                'buyer_name' => 'Test Client',
                'service_name' => 'Website Maintenance',
                'hsn_sac' => '998313',
                'base_amount' => 3258.00,
                'cgst_rate' => 9,
                'cgst_amount' => 293.22,
                'sgst_rate' => 9,
                'sgst_amount' => 293.22,
                'total_amount' => 3844.44,
            ],
        ]);

        $xml = app(TallyExportService::class)->exportForFile($file);

        expect($xml)
            ->toContain('<GSTHSNNAME>998313</GSTHSNNAME>')
            ->toContain('<GSTOVRDNTAXABILITY>Taxable</GSTOVRDNTAXABILITY>')
            ->toContain('<GSTOVRDNTYPEOFSUPPLY>Services</GSTOVRDNTYPEOFSUPPLY>');
    });

    it('includes RATEOFINVOICETAX in each tax ledger entry', function () {
        $file = ImportedFile::factory()->create([
            'statement_type' => StatementType::Invoice,
            'company_id' => tenant()->id,
        ]);
        $head = AccountHead::factory()->create(['company_id' => tenant()->id]);
        Transaction::factory()->mapped($head)->create([
            'imported_file_id' => $file->id,
            'company_id' => tenant()->id,
            'credit' => '3844.44',
            'date' => '2026-04-01',
            'raw_data' => [
                'buyer_name' => 'Test Client',
                'service_name' => 'IT Services',
                'base_amount' => 3258.00,
                'cgst_rate' => 9,
                'cgst_amount' => 293.22,
                'sgst_rate' => 9,
                'sgst_amount' => 293.22,
                'total_amount' => 3844.44,
            ],
        ]);

        $xml = app(TallyExportService::class)->exportForFile($file);

        expect($xml)
            ->toContain('<RATEOFINVOICETAX.LIST TYPE="Number">')
            ->toContain('<RATEOFINVOICETAX>9</RATEOFINVOICETAX>');
    });

    it('populates ADDRESS.LIST from buyer_address', function () {
        $file = ImportedFile::factory()->create([
            'statement_type' => StatementType::Invoice,
            'company_id' => tenant()->id,
        ]);
        $head = AccountHead::factory()->create(['company_id' => tenant()->id]);
        Transaction::factory()->mapped($head)->create([
            'imported_file_id' => $file->id,
            'company_id' => tenant()->id,
            'credit' => '3844.44',
            'date' => '2026-04-01',
            'raw_data' => [
                'buyer_name' => 'VYGNIK BEHAVIORAL SERVICES PRIVATE LIMITED',
                'buyer_address' => ['NO 10 & 11 NAT STREET BASAVANAGUDI', 'BANGALORE 560004'],
                'service_name' => 'IT Services',
                'base_amount' => 3258.00,
                'cgst_rate' => 9,
                'cgst_amount' => 293.22,
                'sgst_rate' => 9,
                'sgst_amount' => 293.22,
                'total_amount' => 3844.44,
            ],
        ]);

        $xml = app(TallyExportService::class)->exportForFile($file);

        expect($xml)
            ->toContain('<ADDRESS.LIST TYPE="String">')
            ->toContain('NO 10 &amp; 11 NAT STREET BASAVANAGUDI')
            ->toContain('BANGALORE 560004');
    });

    it('includes buyer GSTIN as PARTYGSTIN', function () {
        $file = ImportedFile::factory()->create([
            'statement_type' => StatementType::Invoice,
            'company_id' => tenant()->id,
        ]);
        $head = AccountHead::factory()->create(['company_id' => tenant()->id]);
        Transaction::factory()->mapped($head)->create([
            'imported_file_id' => $file->id,
            'company_id' => tenant()->id,
            'credit' => '3844.44',
            'date' => '2026-04-01',
            'raw_data' => [
                'buyer_name' => 'VYGNIK BEHAVIORAL SERVICES PRIVATE LIMITED',
                'buyer_gstin' => '29AAGCV9545C1ZZ',
                'service_name' => 'IT Services',
                'base_amount' => 3258.00,
                'cgst_rate' => 9,
                'cgst_amount' => 293.22,
                'sgst_rate' => 9,
                'sgst_amount' => 293.22,
                'total_amount' => 3844.44,
            ],
        ]);

        $xml = app(TallyExportService::class)->exportForFile($file);

        expect($xml)->toContain('<PARTYGSTIN>29AAGCV9545C1ZZ</PARTYGSTIN>');
    });

    it('uses invoice_date from raw_data not the transaction date', function () {
        $file = ImportedFile::factory()->create([
            'statement_type' => StatementType::Invoice,
            'company_id' => tenant()->id,
        ]);
        $head = AccountHead::factory()->create(['company_id' => tenant()->id]);
        Transaction::factory()->mapped($head)->create([
            'imported_file_id' => $file->id,
            'company_id' => tenant()->id,
            'credit' => '3844.44',
            'date' => '2026-04-15',
            'raw_data' => [
                'buyer_name' => 'Test Client',
                'service_name' => 'IT Services',
                'invoice_date' => '2026-04-01',
                'base_amount' => 3258.00,
                'cgst_rate' => 9,
                'cgst_amount' => 293.22,
                'sgst_rate' => 9,
                'sgst_amount' => 293.22,
                'total_amount' => 3844.44,
            ],
        ]);

        $xml = app(TallyExportService::class)->exportForFile($file);

        // Uses invoice_date (20260401) not transaction date (20260415)
        expect($xml)->toContain('<DATE>20260401</DATE>');
    });

    it('falls back to transaction date when invoice_date is absent', function () {
        $file = ImportedFile::factory()->create([
            'statement_type' => StatementType::Invoice,
            'company_id' => tenant()->id,
        ]);
        $head = AccountHead::factory()->create(['company_id' => tenant()->id]);
        Transaction::factory()->mapped($head)->create([
            'imported_file_id' => $file->id,
            'company_id' => tenant()->id,
            'credit' => '3844.44',
            'date' => '2026-04-15',
            'raw_data' => [
                'buyer_name' => 'Test Client',
                'service_name' => 'IT Services',
                'base_amount' => 3258.00,
                'cgst_rate' => 9,
                'cgst_amount' => 293.22,
                'sgst_rate' => 9,
                'sgst_amount' => 293.22,
                'total_amount' => 3844.44,
            ],
        ]);

        $xml = app(TallyExportService::class)->exportForFile($file);

        expect($xml)->toContain('<DATE>20260415</DATE>');
    });

    it('includes ISINVOICE No in sales voucher', function () {
        $file = ImportedFile::factory()->create([
            'statement_type' => StatementType::Invoice,
            'company_id' => tenant()->id,
        ]);
        $head = AccountHead::factory()->create(['company_id' => tenant()->id]);
        Transaction::factory()->mapped($head)->create([
            'imported_file_id' => $file->id,
            'company_id' => tenant()->id,
            'credit' => '3844.44',
            'date' => '2026-04-01',
            'raw_data' => [
                'buyer_name' => 'Test Client',
                'service_name' => 'IT Services',
                'base_amount' => 3258.00,
                'cgst_rate' => 9,
                'cgst_amount' => 293.22,
                'sgst_rate' => 9,
                'sgst_amount' => 293.22,
                'total_amount' => 3844.44,
            ],
        ]);

        $xml = app(TallyExportService::class)->exportForFile($file);

        expect($xml)->toContain('<ISINVOICE>No</ISINVOICE>');
    });

    it('does not affect existing journal voucher export for purchase invoices', function () {
        $file = ImportedFile::factory()->create([
            'statement_type' => StatementType::Invoice,
            'company_id' => tenant()->id,
        ]);
        $head = AccountHead::factory()->create(['company_id' => tenant()->id]);
        Transaction::factory()->mapped($head)->create([
            'imported_file_id' => $file->id,
            'company_id' => tenant()->id,
            'debit' => '31900',
            'date' => '2025-04-01',
            'raw_data' => [
                'vendor_name' => 'Test Vendor Pvt Ltd',
                'base_amount' => 27500.00,
                'cgst_rate' => 9,
                'cgst_amount' => 2475.00,
                'sgst_rate' => 9,
                'sgst_amount' => 2475.00,
                'igst_amount' => null,
                'tds_amount' => 0,
                'total_amount' => 31900.00,
            ],
        ]);

        $xml = app(TallyExportService::class)->exportForFile($file);

        expect($xml)
            ->toContain('VCHTYPE="Journal"')
            ->toContain('<REPORTNAME>All Masters</REPORTNAME>')
            ->not->toContain('VCHTYPE="Sales"');
    });

    it('includes line item description in narration when line_items present', function () {
        $file = ImportedFile::factory()->create([
            'statement_type' => StatementType::Invoice,
            'company_id' => tenant()->id,
        ]);
        $head = AccountHead::factory()->create(['company_id' => tenant()->id]);
        Transaction::factory()->mapped($head)->create([
            'imported_file_id' => $file->id,
            'company_id' => tenant()->id,
            'credit' => '3844.44',
            'date' => '2026-04-01',
            'raw_data' => [
                'buyer_name' => 'VYGNIK BEHAVIORAL SERVICES PRIVATE LIMITED',
                'service_name' => 'Website Maintenance',
                'base_amount' => 3258.00,
                'cgst_rate' => 9,
                'cgst_amount' => 293.22,
                'sgst_rate' => 9,
                'sgst_amount' => 293.22,
                'total_amount' => 3844.44,
                'line_items' => [
                    ['description' => 'Website Maintenance - AWS, Vercel, Digital Ocean & AWS Lambda', 'amount' => 3258.00],
                ],
            ],
        ]);

        $xml = app(TallyExportService::class)->exportForFile($file);

        expect($xml)->toContain('<NARRATION>AWS, Vercel, Digital Ocean &amp; AWS Lambda</NARRATION>');
    });

    it('strips service_name prefix from flat description when no line_items present', function () {
        $file = ImportedFile::factory()->create([
            'statement_type' => StatementType::Invoice,
            'company_id' => tenant()->id,
        ]);
        $head = AccountHead::factory()->create(['company_id' => tenant()->id]);
        Transaction::factory()->mapped($head)->create([
            'imported_file_id' => $file->id,
            'company_id' => tenant()->id,
            'credit' => '3844.44',
            'date' => '2026-04-01',
            'raw_data' => [
                'buyer_name' => 'Test Client',
                'service_name' => 'Website Maintenance',
                'base_amount' => 3258.00,
                'cgst_rate' => 9,
                'cgst_amount' => 293.22,
                'sgst_rate' => 9,
                'sgst_amount' => 293.22,
                'total_amount' => 3844.44,
                'description' => 'Website Maintenance - AWS, Vercel, Digital Ocean & AWS Lambda',
            ],
        ]);

        $xml = app(TallyExportService::class)->exportForFile($file);

        expect($xml)->toContain('<NARRATION>AWS, Vercel, Digital Ocean &amp; AWS Lambda</NARRATION>');
    });

    it('strips service_name prefix separated by a space (no dash) from line item description', function () {
        $file = ImportedFile::factory()->create([
            'statement_type' => StatementType::Invoice,
            'company_id' => tenant()->id,
        ]);
        $head = AccountHead::factory()->create(['company_id' => tenant()->id]);
        Transaction::factory()->mapped($head)->create([
            'imported_file_id' => $file->id,
            'company_id' => tenant()->id,
            'credit' => '68440',
            'date' => '2026-04-13',
            'raw_data' => [
                'buyer_name' => 'Technology Informatics Design Endeavour',
                'service_name' => 'Website Maintenance',
                'base_amount' => 58000,
                'cgst_rate' => 9,
                'cgst_amount' => 5220,
                'sgst_rate' => 9,
                'sgst_amount' => 5220,
                'total_amount' => 68440,
                'line_items' => [
                    ['description' => 'Website Maintenance WATSAN Security & OS Patch Updates', 'amount' => 58000],
                ],
            ],
        ]);

        $xml = app(TallyExportService::class)->exportForFile($file);

        expect($xml)->toContain('<NARRATION>WATSAN Security &amp; OS Patch Updates</NARRATION>');
    });

    it('strips wrapping parentheses from narration after service prefix removal', function () {
        $file = ImportedFile::factory()->create([
            'statement_type' => StatementType::Invoice,
            'company_id' => tenant()->id,
        ]);
        $head = AccountHead::factory()->create(['company_id' => tenant()->id]);
        Transaction::factory()->mapped($head)->create([
            'imported_file_id' => $file->id,
            'company_id' => tenant()->id,
            'credit' => '3844.44',
            'date' => '2026-04-01',
            'raw_data' => [
                'buyer_name' => 'VYGNIK BEHAVIORAL SERVICES PRIVATE LIMITED',
                'service_name' => 'Website Maintenance',
                'base_amount' => 3258.00,
                'cgst_rate' => 9,
                'cgst_amount' => 293.22,
                'sgst_rate' => 9,
                'sgst_amount' => 293.22,
                'total_amount' => 3844.44,
                'line_items' => [
                    ['description' => 'Website Maintenance - (AWS, Vercel, Digital Ocean & AWS Lambda)', 'amount' => 3258.00],
                ],
            ],
        ]);

        $xml = app(TallyExportService::class)->exportForFile($file);

        expect($xml)->toContain('<NARRATION>AWS, Vercel, Digital Ocean &amp; AWS Lambda</NARRATION>');
    });

    it('does not strip prefix when service_name does not match description prefix', function () {
        $file = ImportedFile::factory()->create([
            'statement_type' => StatementType::Invoice,
            'company_id' => tenant()->id,
        ]);
        $head = AccountHead::factory()->create(['company_id' => tenant()->id]);
        Transaction::factory()->mapped($head)->create([
            'imported_file_id' => $file->id,
            'company_id' => tenant()->id,
            'credit' => '3844.44',
            'date' => '2026-04-01',
            'raw_data' => [
                'buyer_name' => 'Test Client',
                'service_name' => 'IT Services',
                'base_amount' => 3258.00,
                'cgst_rate' => 9,
                'cgst_amount' => 293.22,
                'sgst_rate' => 9,
                'sgst_amount' => 293.22,
                'total_amount' => 3844.44,
                'line_items' => [
                    ['description' => 'Website Maintenance - AWS, Vercel', 'amount' => 3258.00],
                ],
            ],
        ]);

        $xml = app(TallyExportService::class)->exportForFile($file);

        expect($xml)->toContain('<NARRATION>Website Maintenance - AWS, Vercel</NARRATION>');
    });

    it('joins multiple line item descriptions with newlines in narration', function () {
        $file = ImportedFile::factory()->create([
            'statement_type' => StatementType::Invoice,
            'company_id' => tenant()->id,
        ]);
        $head = AccountHead::factory()->create(['company_id' => tenant()->id]);
        Transaction::factory()->mapped($head)->create([
            'imported_file_id' => $file->id,
            'company_id' => tenant()->id,
            'credit' => '5000.00',
            'date' => '2026-04-01',
            'raw_data' => [
                'buyer_name' => 'Test Client',
                'service_name' => 'IT Services',
                'base_amount' => 5000.00,
                'cgst_rate' => 9,
                'cgst_amount' => 450.00,
                'sgst_rate' => 9,
                'sgst_amount' => 450.00,
                'total_amount' => 5900.00,
                'line_items' => [
                    ['description' => 'Website Maintenance - AWS, Vercel', 'amount' => 3000.00],
                    ['description' => 'Domain Renewal - example.com', 'amount' => 2000.00],
                ],
            ],
        ]);

        $xml = app(TallyExportService::class)->exportForFile($file);

        expect($xml)->toContain("Website Maintenance - AWS, Vercel\nDomain Renewal - example.com");
    });

    it('falls back to description field when line_items absent in sales voucher', function () {
        $file = ImportedFile::factory()->create([
            'statement_type' => StatementType::Invoice,
            'company_id' => tenant()->id,
        ]);
        $head = AccountHead::factory()->create(['company_id' => tenant()->id]);
        Transaction::factory()->mapped($head)->create([
            'imported_file_id' => $file->id,
            'company_id' => tenant()->id,
            'credit' => '3844.44',
            'date' => '2026-04-01',
            'raw_data' => [
                'buyer_name' => 'Test Client',
                'service_name' => 'IT Services',
                'description' => 'Fallback description',
                'base_amount' => 3258.00,
                'cgst_rate' => 9,
                'cgst_amount' => 293.22,
                'sgst_rate' => 9,
                'sgst_amount' => 293.22,
                'total_amount' => 3844.44,
            ],
        ]);

        $xml = app(TallyExportService::class)->exportForFile($file);

        expect($xml)->toContain('<NARRATION>Fallback description</NARRATION>');
    });

    it('includes PLACEOFSUPPLY and STATENAME from raw_data', function () {
        $file = ImportedFile::factory()->create([
            'statement_type' => StatementType::Invoice,
            'company_id' => tenant()->id,
        ]);
        $head = AccountHead::factory()->create(['company_id' => tenant()->id]);
        Transaction::factory()->mapped($head)->create([
            'imported_file_id' => $file->id,
            'company_id' => tenant()->id,
            'credit' => '3844.44',
            'date' => '2026-04-01',
            'raw_data' => [
                'buyer_name' => 'Test Client',
                'place_of_supply' => 'Karnataka',
                'service_name' => 'IT Services',
                'base_amount' => 3258.00,
                'cgst_rate' => 9,
                'cgst_amount' => 293.22,
                'sgst_rate' => 9,
                'sgst_amount' => 293.22,
                'total_amount' => 3844.44,
            ],
        ]);

        $xml = app(TallyExportService::class)->exportForFile($file);

        expect($xml)
            ->toContain('<PLACEOFSUPPLY>Karnataka</PLACEOFSUPPLY>')
            ->toContain('<STATENAME>Karnataka</STATENAME>');
    });

    it('derives service_name from line item prefix when service_name is absent', function () {
        $file = ImportedFile::factory()->create([
            'statement_type' => StatementType::Invoice,
            'company_id' => tenant()->id,
        ]);
        $head = AccountHead::factory()->create([
            'company_id' => tenant()->id,
            'name' => 'Branch / Divisions',
        ]);
        Transaction::factory()->mapped($head)->create([
            'imported_file_id' => $file->id,
            'company_id' => tenant()->id,
            'credit' => '68440',
            'date' => '2026-04-13',
            'raw_data' => [
                'buyer_name' => 'Technology Informatics Design Endeavour',
                // No service_name — simulates InvoiceParser missing the field
                'base_amount' => 58000,
                'cgst_rate' => 9,
                'cgst_amount' => 5220,
                'sgst_rate' => 9,
                'sgst_amount' => 5220,
                'total_amount' => 68440,
                'line_items' => [
                    ['description' => 'Website Maintenance - WATSAN Security & OS Patch Updates', 'amount' => 58000],
                ],
            ],
        ]);

        $xml = app(TallyExportService::class)->exportForFile($file);

        expect($xml)
            ->toContain('<LEDGERNAME>Website Maintenance</LEDGERNAME>')
            ->toContain('<NARRATION>WATSAN Security &amp; OS Patch Updates</NARRATION>');
    });
});

describe('InvoiceParser schema for sales invoices', function () {
    it('schema includes buyer_name, buyer_address, buyer_gstin, service_name, hsn_sac fields', function () {
        $parser = app(InvoiceParser::class);
        $schemaBuilder = new JsonSchemaTypeFactory;

        $schema = $parser->schema($schemaBuilder);

        expect($schema)
            ->toHaveKey('buyer_name')
            ->toHaveKey('buyer_address')
            ->toHaveKey('buyer_gstin')
            ->toHaveKey('service_name')
            ->toHaveKey('hsn_sac');
    });
});
