<?php

use App\Domain\Backoffice\Support\Format;

it('groups amounts by three digits with non-breaking spaces', function (int|string $amount, string $expected) {
    expect(Format::xof($amount))->toBe($expected."\u{00A0}FCFA");
})->with([
    'zero' => [0, '0'],
    'hundreds' => [999, '999'],
    'thousands' => [25000, "25\u{00A0}000"],
    'millions' => [5100000, "5\u{00A0}100\u{00A0}000"],
    'large string' => ['123456789012', "123\u{00A0}456\u{00A0}789\u{00A0}012"],
    'negative' => [-1500, "-1\u{00A0}500"],
]);

it('shows YEM quantities without going through floats', function () {
    expect(Format::yem('38.461538'))->toBe("38,46\u{00A0}YEM")
        ->and(Format::yem('38.461538', 6))->toBe("38,461538\u{00A0}YEM")
        ->and(Format::yem('1234567.5', 2))->toBe("1\u{00A0}234\u{00A0}567,50\u{00A0}YEM")
        ->and(Format::yem(null))->toBe("0,00\u{00A0}YEM");
});

it('formats basis points as a percentage', function () {
    expect(Format::percent(1500))->toBe("15\u{00A0}%")
        ->and(Format::percent(633))->toBe("6,3\u{00A0}%")
        ->and(Format::percent(0))->toBe("0\u{00A0}%");
});
