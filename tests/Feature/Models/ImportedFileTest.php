<?php

use App\Enums\ImportSource;
use App\Enums\StatementType;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\ImportedFile;
use App\Models\Transaction;
use App\Models\TransactionAggregate;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

describe('ImportedFile soft deletes', function () {
    it('uses the SoftDeletes trait', function () {
        expect(in_array(SoftDeletes::class, class_uses_recursive(ImportedFile::class)))->toBeTrue();
    });

    it('is excluded from normal queries after soft delete', function () {
        $file = ImportedFile::factory()->create();

        $file->delete();

        expect(ImportedFile::find($file->id))->toBeNull();
    });

    it('can be restored after soft delete', function () {
        $file = ImportedFile::factory()->create();
        $file->delete();

        $file->restore();

        expect(ImportedFile::find($file->id))->not->toBeNull();
    });

    it('is permanently removed after force delete', function () {
        $file = ImportedFile::factory()->create();

        $file->forceDelete();

        expect(ImportedFile::withTrashed()->find($file->id))->toBeNull();
    });

    it('is included in withTrashed queries after soft delete', function () {
        $file = ImportedFile::factory()->create();
        $file->delete();

        expect(ImportedFile::withTrashed()->find($file->id))->not->toBeNull();
        expect(ImportedFile::withTrashed()->find($file->id)->trashed())->toBeTrue();
    });

    it('does not delete file from storage on soft delete', function () {
        Storage::fake('local');
        Storage::disk('local')->put('statements/test.pdf', 'content');

        $file = ImportedFile::factory()->create(['file_path' => 'statements/test.pdf']);
        $file->delete();

        Storage::disk('local')->assertExists('statements/test.pdf');
    });

    it('deletes file from storage on force delete', function () {
        Storage::fake('local');
        Storage::disk('local')->put('statements/test.pdf', 'content');

        $file = ImportedFile::factory()->create(['file_path' => 'statements/test.pdf']);
        $file->forceDelete();

        Storage::disk('local')->assertMissing('statements/test.pdf');
    });
});

describe('ImportedFile cascade soft deletes', function () {
    it('soft-deletes associated transactions when soft-deleted', function () {
        $file = ImportedFile::factory()->create();
        $transactions = Transaction::factory()->for($file, 'importedFile')->count(3)->create();

        $file->delete();

        foreach ($transactions as $transaction) {
            expect(Transaction::find($transaction->id))->toBeNull();
            expect(Transaction::withTrashed()->find($transaction->id)->trashed())->toBeTrue();
        }
    });

    it('restores associated transactions when restored', function () {
        $file = ImportedFile::factory()->create();
        $transactions = Transaction::factory()->for($file, 'importedFile')->count(3)->create();
        $file->delete();

        $file->restore();

        foreach ($transactions as $transaction) {
            expect(Transaction::find($transaction->id))->not->toBeNull();
        }
    });
});

describe('ImportedFile::mappedPercentage', function () {
    it('returns 0 when total_rows is zero', function () {
        $file = ImportedFile::factory()->create(['total_rows' => 0, 'mapped_rows' => 0]);

        expect($file->mapped_percentage)->toBe(0.0);
    });

    it('calculates percentage correctly', function () {
        $file = ImportedFile::factory()->completed(totalRows: 100, mappedRows: 60)->create();

        expect($file->mapped_percentage)->toBe(60.0);
    });

    it('rounds to one decimal place', function () {
        $file = ImportedFile::factory()->completed(totalRows: 3, mappedRows: 1)->create();

        expect($file->mapped_percentage)->toBe(33.3);
    });

    it('returns 100 when all rows are mapped', function () {
        $file = ImportedFile::factory()->completed(totalRows: 50, mappedRows: 50)->create();

        expect($file->mapped_percentage)->toBe(100.0);
    });
});

describe('ImportedFile forceDeleting event', function () {
    it('deletes the file from storage when model is force-deleted', function () {
        Storage::fake('local');
        Storage::disk('local')->put('statements/test.pdf', 'content');

        $file = ImportedFile::factory()->create(['file_path' => 'statements/test.pdf']);
        $file->forceDelete();

        Storage::disk('local')->assertMissing('statements/test.pdf');
    });

    it('does not fail when file does not exist on disk during force delete', function () {
        Storage::fake('local');

        $file = ImportedFile::factory()->create(['file_path' => 'statements/nonexistent.pdf']);
        $file->forceDelete();

        expect(true)->toBeTrue(); // No exception thrown
    });
});

describe('ImportedFile relationships', function () {
    it('belongs to an uploader', function () {
        $file = ImportedFile::factory()->create();

        expect($file->uploader)->not->toBeNull();
    });

    it('has many transactions', function () {
        $file = ImportedFile::factory()->create();

        expect($file->transactions)->toBeEmpty();
    });
});

describe('ImportedFile force-delete aggregate cleanup', function () {
    beforeEach(function () {
        asUser();
    });

    it('removes TransactionAggregate contribution when force-deleted', function () {
        $company = tenant();
        $file = ImportedFile::factory()->create(['company_id' => $company->id]);

        Transaction::factory()->for($file, 'importedFile')->debit(5000)->create([
            'company_id' => $company->id,
            'date' => '2025-04-15',
        ]);

        expect(TransactionAggregate::where('company_id', $company->id)
            ->where('year_month', '2025-04')
            ->exists()
        )->toBeTrue();

        $file->forceDelete();

        expect(TransactionAggregate::where('company_id', $company->id)
            ->where('year_month', '2025-04')
            ->exists()
        )->toBeFalse();
    });

    it('preserves aggregates from other files in the same month', function () {
        $company = tenant();
        $file1 = ImportedFile::factory()->create(['company_id' => $company->id]);
        $file2 = ImportedFile::factory()->create(['company_id' => $company->id]);

        Transaction::factory()->for($file1, 'importedFile')->debit(3000)->create([
            'company_id' => $company->id,
            'date' => '2025-04-10',
        ]);
        Transaction::factory()->for($file2, 'importedFile')->debit(2000)->create([
            'company_id' => $company->id,
            'date' => '2025-04-20',
        ]);

        $file1->forceDelete();

        $aggregate = TransactionAggregate::where('company_id', $company->id)
            ->where('year_month', '2025-04')
            ->first();

        expect($aggregate)->not->toBeNull()
            ->and((float) $aggregate->total_debit)->toBe(2000.0);
    });

    it('does not error when a soft-deleted file is force-deleted', function () {
        $company = tenant();
        $file = ImportedFile::factory()->create(['company_id' => $company->id]);

        Transaction::factory()->for($file, 'importedFile')->debit(5000)->create([
            'company_id' => $company->id,
            'date' => '2025-04-15',
        ]);

        $file->delete(); // soft-delete → transactions soft-deleted → aggregate decremented to 0

        $file->forceDelete(); // should not throw or double-decrement

        expect(ImportedFile::withTrashed()->find($file->id))->toBeNull();
    });

    it('does not affect the soft-delete path', function () {
        $company = tenant();
        $file = ImportedFile::factory()->create(['company_id' => $company->id]);

        Transaction::factory()->for($file, 'importedFile')->debit(5000)->create([
            'company_id' => $company->id,
            'date' => '2025-04-15',
        ]);

        $file->delete();

        // Soft-delete still decrements aggregates via TransactionObserver (unchanged behavior)
        $aggregate = TransactionAggregate::where('company_id', $company->id)
            ->where('year_month', '2025-04')
            ->first();

        expect($aggregate)->not->toBeNull()
            ->and((float) $aggregate->total_debit)->toBe(0.0)
            ->and($aggregate->transaction_count)->toBe(0);
    });
});

describe('ImportedFile::getFullBankOrCardName', function () {
    it('collapses a card variant that is contained in the card name', function () {
        $card = CreditCard::factory()->create(['name' => 'ICICI Bank']);
        $file = ImportedFile::factory()->create([
            'statement_type' => StatementType::CreditCard,
            'credit_card_id' => $card->id,
            'bank_name' => 'ICICI Bank',
            'card_variant' => 'ICICI',
        ]);

        expect($file->getFullBankOrCardName())->toBe('ICICI Bank');
    });

    it('collapses a card variant that is contained in the bank name', function () {
        $file = ImportedFile::factory()->create([
            'statement_type' => StatementType::CreditCard,
            'credit_card_id' => null,
            'bank_name' => 'HDFC Bank',
            'card_variant' => 'HDFC',
        ]);

        expect($file->getFullBankOrCardName())->toBe('HDFC Bank');
    });

    it('prefers the card variant over the linked card record name', function () {
        $card = CreditCard::factory()->create(['name' => 'HDFC Credit Card']);
        $file = ImportedFile::factory()->create([
            'statement_type' => StatementType::CreditCard,
            'credit_card_id' => $card->id,
            'bank_name' => 'HDFC Bank',
            'card_variant' => 'Millennia',
        ]);

        expect($file->getFullBankOrCardName())->toBe('HDFC Bank Millennia');
    });

    it('keeps a card variant that does not overlap the bank name', function () {
        $file = ImportedFile::factory()->create([
            'statement_type' => StatementType::CreditCard,
            'credit_card_id' => null,
            'bank_name' => 'HDFC Bank',
            'card_variant' => 'Paytm',
        ]);

        expect($file->getFullBankOrCardName())->toBe('HDFC Bank Paytm');
    });

    it('returns the detected bank name for a bank statement with a linked bank account', function () {
        $account = BankAccount::factory()->create(['name' => 'Zysk Current A/c 5001']);
        $file = ImportedFile::factory()->create([
            'statement_type' => StatementType::Bank,
            'bank_account_id' => $account->id,
            'bank_name' => 'HDFC Bank',
        ]);

        expect($file->getFullBankOrCardName())->toBe('HDFC Bank');
    });
});

describe('ImportedFile encryption', function () {
    it('encrypts and decrypts account_number', function () {
        $file = ImportedFile::factory()->create(['account_number' => '1234567890']);

        $fresh = ImportedFile::find($file->id);
        expect($fresh->account_number)->toBe('1234567890');
    });
});

describe('ImportedFile source tracking', function () {
    it('defaults to ManualUpload source', function () {
        $file = ImportedFile::factory()->create();

        expect($file->source)->toBe(ImportSource::ManualUpload)
            ->and($file->source_metadata)->toBeNull();
    });

    it('creates from email with metadata', function () {
        $file = ImportedFile::factory()->fromEmail('<msg-123@example.com>')->create();

        expect($file->source)->toBe(ImportSource::Email)
            ->and($file->source_metadata)->toBeArray()
            ->and($file->source_metadata['message_id'])->toBe('<msg-123@example.com>');
    });

    it('creates from zoho with metadata', function () {
        $file = ImportedFile::factory()->fromZoho('INV-001')->create();

        expect($file->source)->toBe(ImportSource::Zoho)
            ->and($file->source_metadata)->toBeArray()
            ->and($file->source_metadata['zoho_invoice_id'])->toBe('INV-001');
    });

    it('encrypts and decrypts source_metadata', function () {
        $metadata = ['message_id' => '<test@example.com>', 'from' => 'vendor@example.com'];
        $file = ImportedFile::factory()->create(['source_metadata' => $metadata]);

        $fresh = ImportedFile::find($file->id);
        expect($fresh->source_metadata)->toBe($metadata);
    });

    it('populates message_id column from fromEmail factory state', function () {
        $file = ImportedFile::factory()->fromEmail('<factory-msg@example.com>')->create();

        expect($file->message_id)->toBe('<factory-msg@example.com>')
            ->and($file->source_metadata['message_id'])->toBe('<factory-msg@example.com>');
    });

    it('has null message_id for non-email sources', function () {
        $file = ImportedFile::factory()->create();

        expect($file->message_id)->toBeNull();
    });
});
