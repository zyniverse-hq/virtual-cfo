<?php

use App\Ai\Agents\HeadMatcher;
use App\Ai\Agents\InvoiceParser;
use App\Ai\Agents\StatementParser;
use App\Ai\Middleware\AuditLlmCalls;
use App\Enums\ConnectorProvider;
use App\Enums\ImportSource;
use App\Enums\ImportStatus;
use App\Enums\MappingType;
use App\Enums\MatchType;
use App\Enums\StatementType;

describe('Models', function () {
    it('extends Eloquent Model or Authenticatable', function () {
        expect('App\Models')
            ->classes()
            ->toExtend('Illuminate\Database\Eloquent\Model')
            ->ignoring('App\Models\User');
    });

    it('User extends Authenticatable', function () {
        expect('App\Models\User')
            ->toExtend('Illuminate\Foundation\Auth\User');
    });
})->group('architecture');

describe('Enums', function () {
    it('are in the correct namespace', function () {
        expect('App\Enums')
            ->toBeEnums();
    });

    it('are string-backed', function () {
        $enums = [
            ConnectorProvider::class,
            ImportSource::class,
            ImportStatus::class,
            MappingType::class,
            MatchType::class,
            StatementType::class,
        ];

        foreach ($enums as $enum) {
            $reflection = new ReflectionEnum($enum);
            expect($reflection->getBackingType()?->getName())->toBe('string', "{$enum} should be string-backed");
        }
    });
})->group('architecture');

describe('Filament Resources', function () {
    it('extends Resource', function () {
        expect('App\Filament\Resources')
            ->classes()
            ->toExtend('Filament\Resources\Resource')
            ->ignoring('App\Filament\Resources\AccountHeadResource\Pages')
            ->ignoring('App\Filament\Resources\ActivityLogResource\Pages')
            ->ignoring('App\Filament\Resources\BankAccountResource\Pages')
            ->ignoring('App\Filament\Resources\CreditCardResource\Pages')
            ->ignoring('App\Filament\Resources\HeadMappingResource\Pages')
            ->ignoring('App\Filament\Resources\ImportedFileResource\Pages')
            ->ignoring('App\Filament\Resources\ReconciliationResource\Pages')
            ->ignoring('App\Filament\Resources\RecurringPatternResource\Pages')
            ->ignoring('App\Filament\Resources\TeamMemberResource\Pages')
            ->ignoring('App\Filament\Resources\TransactionResource\Pages')
            ->ignoring('App\Filament\Resources\DuplicateFlags\Pages')
            ->ignoring('App\Filament\Resources\BudgetResource\Pages')
            ->ignoring('App\Filament\Resources\InboundEmailResource\Pages')
            ->ignoring('App\Filament\Resources\CashTransactions\Pages')
            ->ignoring('App\Filament\Resources\ImportedFileResource\RelationManagers');
    });
})->group('architecture');

describe('Jobs', function () {
    it('implements ShouldQueue', function () {
        expect('App\Jobs')
            ->classes()
            ->toImplement('Illuminate\Contracts\Queue\ShouldQueue')
            ->ignoring('App\Jobs\Middleware');
    });
})->group('architecture');

describe('AI Agents', function () {
    it('implements HasMiddleware', function () {
        expect('App\Ai\Agents')
            ->classes()
            ->toImplement('Laravel\Ai\Contracts\HasMiddleware');
    });

    it('includes AuditLlmCalls in middleware', function () {
        $agents = [
            new HeadMatcher,
            new StatementParser,
            new InvoiceParser,
        ];

        foreach ($agents as $agent) {
            $middleware = $agent->middleware();
            $hasAudit = false;

            foreach ($middleware as $mw) {
                if ($mw instanceof AuditLlmCalls) {
                    $hasAudit = true;
                }
            }

            expect($hasAudit)->toBeTrue(class_basename($agent).' missing AuditLlmCalls middleware');
        }
    });
})->group('architecture');

describe('Strict types', function () {
    it('does not use env() outside config files', function () {
        expect(['App'])
            ->not->toUse(['env']);
    });

    it('does not use die or dd', function () {
        expect(['App'])
            ->not->toUse(['die', 'dd', 'dump']);
    });
})->group('architecture');
