<?php

use App\Services\DocumentProcessor\AmountParser;

beforeEach(function () {
    $this->parser = new AmountParser;
});

describe('AmountParser::parse()', function () {
    it('reads the absolute value, sign and Cr/Dr marker of an amount', function (mixed $value, string $amount, ?string $sign, ?string $marker) {
        expect($this->parser->parse($value))->toBe(['amount' => $amount, 'sign' => $sign, 'marker' => $marker]);
    })->with([
        'plain number' => [1500, '1500', null, null],
        'float' => [1505.8, '1505.8', null, null],
        'negative number' => [-500, '500', '-', null],
        'thousands separators' => ['1,234.50', '1234.50', null, null],
        'indian lakh grouping' => ['12,34,567.89', '1234567.89', null, null],
        'large float without scientific notation' => [1.0e15, '1000000000000000', null, null],
        'space grouped thousands' => ['INR 1 500.00', '1500.00', null, null],
        'hyphen inside a reference is not a sign' => ['Ref-12 500.00', '12500.00', null, null],
        'minus after currency' => ['₹ -500.00', '500.00', '-', null],
        'currency prefix' => ['INR 1,500.00', '1500.00', null, null],
        'rupee prefix with dot' => ['Rs. 1,500.00', '1500.00', null, null],
        'rupee symbol' => ['₹1,500.00', '1500.00', null, null],
        'leading minus' => ['-750.00', '750.00', '-', null],
        'trailing minus' => ['750.00-', '750.00', '-', null],
        'leading plus' => ['+750.00', '750.00', '+', null],
        'plus separated by rupee symbol' => ['+ ₹ 1,104.00', '1104.00', '+', null],
        'brackets' => ['(1,000.00)', '1000.00', '-', null],
        'Cr suffix' => ['9,148.42 CR', '9148.42', null, 'credit'],
        'Cr. suffix with dot' => ['500.00 Cr.', '500.00', null, 'credit'],
        'Dr suffix' => ['250.00 Dr', '250.00', null, 'debit'],
        'Dr. suffix with dot' => ['500.00 Dr.', '500.00', null, 'debit'],
        'attached Cr suffix' => ['500.00Cr', '500.00', null, 'credit'],
        'Cr prefix' => ['CR 500.00', '500.00', null, 'credit'],
        'SBI-style C suffix' => ['1,234.00 C', '1234.00', null, 'credit'],
        'SBI-style D suffix' => ['1,234.00 D', '1234.00', null, 'debit'],
        'rupee extracted as C before the number is not a marker' => ['C 1,950.00', '1950.00', null, null],
        'sign and marker together' => ['-500.00 Cr', '500.00', '-', 'credit'],
    ]);

    it('returns null when there is no amount', function (mixed $value) {
        expect($this->parser->parse($value))->toBeNull();
    })->with([
        'null' => [null],
        'empty string' => [''],
        'whitespace' => ['   '],
        'dash placeholder' => ['-'],
        'text only' => ['N/A'],
    ]);
});

describe('AmountParser::resolve()', function () {
    it('keeps amounts in the column they came from', function () {
        expect($this->parser->resolve('1,500.00', null))->toBe(['debit' => '1500.00', 'credit' => null])
            ->and($this->parser->resolve(null, '2,000.00'))->toBe(['debit' => null, 'credit' => '2000.00']);
    });

    it('uses the absolute value of a signed amount in a debit or credit column', function () {
        expect($this->parser->resolve(-500, null))->toBe(['debit' => '500', 'credit' => null])
            ->and($this->parser->resolve(null, '-250.00'))->toBe(['debit' => null, 'credit' => '250.00']);
    });

    it('moves an amount to the side its Cr/Dr marker names', function () {
        expect($this->parser->resolve('1,234.50 Cr', null))->toBe(['debit' => null, 'credit' => '1234.50'])
            ->and($this->parser->resolve(null, '99.00 Dr'))->toBe(['debit' => '99.00', 'credit' => null]);
    });

    it('ignores zero and placeholder values', function () {
        expect($this->parser->resolve('-', '250.00'))->toBe(['debit' => null, 'credit' => '250.00'])
            ->and($this->parser->resolve(0, '0.00'))->toBe(['debit' => null, 'credit' => null]);
    });

    it('splits a single signed amount column by its sign', function () {
        expect($this->parser->resolve(null, null, '-1,200.00'))->toBe(['debit' => '1200.00', 'credit' => null])
            ->and($this->parser->resolve(null, null, '+1,200.00'))->toBe(['debit' => null, 'credit' => '1200.00']);
    });

    it('splits a single amount column by its Cr/Dr marker', function () {
        expect($this->parser->resolve(null, null, '9,148.42 CR'))->toBe(['debit' => null, 'credit' => '9148.42'])
            ->and($this->parser->resolve(null, null, '9,148.42 DR'))->toBe(['debit' => '9148.42', 'credit' => null]);
    });

    it('splits a single amount column by a separate Dr/Cr indicator', function (string $indicator, string $side) {
        $expected = ['debit' => null, 'credit' => null];
        $expected[$side] = '1200.00';

        expect($this->parser->resolve(null, null, '1,200.00', $indicator))->toBe($expected);
    })->with([
        ['CR', 'credit'],
        ['Cr.', 'credit'],
        ['C', 'credit'],
        ['Credit', 'credit'],
        ['DR', 'debit'],
        ['D', 'debit'],
        ['Debit', 'debit'],
    ]);

    it('lets a Cr/Dr marker beat the sign of a single amount', function () {
        expect($this->parser->resolve(null, null, '-500.00 Cr'))->toBe(['debit' => null, 'credit' => '500.00']);
    });

    it('treats a minus sign as a credit on credit card statements', function () {
        expect($this->parser->resolve(null, null, '-1,200.00', negativeSide: AmountParser::CREDIT))
            ->toBe(['debit' => null, 'credit' => '1200.00'])
            ->and($this->parser->resolve(null, null, '(1,200.00)', negativeSide: AmountParser::CREDIT))
            ->toBe(['debit' => null, 'credit' => '1200.00'])
            ->and($this->parser->resolve(null, null, '1,200.00 Dr', negativeSide: AmountParser::CREDIT))
            ->toBe(['debit' => '1200.00', 'credit' => null]);
    });

    it('treats a plus sign as a credit on credit card statements', function () {
        expect($this->parser->resolve(null, null, '+ ₹ 1,104.00', negativeSide: AmountParser::CREDIT))
            ->toBe(['debit' => null, 'credit' => '1104.00']);
    });

    it('trusts the column over an unsigned or signed amount on card statements', function () {
        $card = fn (mixed $debit, mixed $credit, string $amount) => $this->parser->resolve($debit, $credit, $amount, negativeSide: AmountParser::CREDIT);

        expect($card(null, 24060, '24,060.00'))->toBe(['debit' => null, 'credit' => '24060'])
            ->and($card(null, 1950, '₹ 1,950.00'))->toBe(['debit' => null, 'credit' => '1950'])
            ->and($card(1104, null, '+ ₹ 1,104.00'))->toBe(['debit' => '1104', 'credit' => null])
            ->and($card(500, null, '(500.00)'))->toBe(['debit' => '500', 'credit' => null]);
    });

    it('moves a column amount only for an explicit Cr/Dr marker', function () {
        $card = fn (mixed $debit, mixed $credit, string $amount) => $this->parser->resolve($debit, $credit, $amount, negativeSide: AmountParser::CREDIT);

        expect($card(500, null, '500.00 Cr'))->toBe(['debit' => null, 'credit' => '500']);
    });

    it('keeps a bank refund in the credit column when the amount is bracketed', function () {
        expect($this->parser->resolve(null, 500, '(500.00)'))->toBe(['debit' => null, 'credit' => '500'])
            ->and($this->parser->resolve(null, 500, '-500.00'))->toBe(['debit' => null, 'credit' => '500']);
    });

    it('books the same card row to the same side with or without a separate amount', function () {
        $pdfRow = $this->parser->resolve(null, 1200, '1,200.00', negativeSide: AmountParser::CREDIT);
        $csvRow = $this->parser->resolve(null, '1,200.00', null, negativeSide: AmountParser::CREDIT);

        expect($pdfRow['credit'])->not->toBeNull()
            ->and($csvRow['credit'])->not->toBeNull()
            ->and($pdfRow['debit'])->toBeNull()
            ->and($csvRow['debit'])->toBeNull();
    });

    it('treats an unmarked single amount as a debit', function () {
        expect($this->parser->resolve(null, null, '1,200.00'))->toBe(['debit' => '1200.00', 'credit' => null]);
    });

    it('drops a placeholder in the unused column when the single amount identifies the real one', function () {
        expect($this->parser->resolve(544, -1, '544.00'))->toBe(['debit' => '544', 'credit' => null])
            ->and($this->parser->resolve(-1, 24060, '24,060.00 CR'))->toBe(['debit' => null, 'credit' => '24060'])
            ->and($this->parser->resolve(100, 200, '300.00'))->toBe(['debit' => '100', 'credit' => '200']);
    });

    it('drops a negative placeholder in the unused column when no single amount is present', function () {
        expect($this->parser->resolve(544, -1))->toBe(['debit' => '544', 'credit' => null])
            ->and($this->parser->resolve('-1', '24,060.00'))->toBe(['debit' => null, 'credit' => '24060.00']);
    });

    it('prefers debit and credit columns over a different single amount', function () {
        expect($this->parser->resolve('300.00', null, '+999.00'))->toBe(['debit' => '300.00', 'credit' => null]);
    });

    it('moves a column amount to the side named by the marker on the verbatim amount for the same value', function () {
        expect($this->parser->resolve(9148.42, null, '9,148.42 CR'))->toBe(['debit' => null, 'credit' => '9148.42'])
            ->and($this->parser->resolve(500, null, '500.00'))->toBe(['debit' => '500', 'credit' => null]);
    });

    it('ignores a trailing standalone C or D that is not the end of the text', function () {
        expect($this->parser->resolve(null, 500, '500.00 D payment'))->toBe(['debit' => null, 'credit' => '500'])
            ->and($this->parser->resolve(null, null, '500.00 C 12'))->toBe(['debit' => '500.00', 'credit' => null]);
    });
});

describe('AmountParser::parseBalance()', function () {
    it('returns a clean signed balance', function (mixed $value, ?string $expected) {
        expect($this->parser->parseBalance($value))->toBe($expected);
    })->with([
        'formatted' => ['98,500.00', '98500.00'],
        'number' => [150000, '150000'],
        'minus sign' => ['-5,000.00', '-5000.00'],
        'brackets' => ['(5,000.00)', '-5000.00'],
        'Cr suffix is stripped' => ['5,000.00 Cr', '5000.00'],
        'Dr suffix is negative' => ['12,000.00 Dr', '-12000.00'],
        'Dr prefix is negative' => ['DR 12,000.00', '-12000.00'],
        'Dr is not negative for a standalone D inside text' => ['12,000.00 D x', '12000.00'],
        'zero is kept' => ['0.00', '0.00'],
        'missing' => [null, null],
        'placeholder' => ['-', null],
    ]);

    it('reads Dr as the amount owed and Cr as a credit balance on card statements', function (string $value, string $expected) {
        expect($this->parser->parseBalance($value, AmountParser::CREDIT))->toBe($expected);
    })->with([
        'Dr is owed' => ['12,000.00 Dr', '12000.00'],
        'Cr is a credit balance' => ['5,000.00 Cr', '-5000.00'],
        'minus sign stays negative' => ['-5,000.00', '-5000.00'],
        'no marker' => ['3,000.00', '3000.00'],
    ]);
});
