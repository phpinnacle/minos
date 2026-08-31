<?php

use PHPinnacle\Minos\Instruments\EncryptedCard;

it('returns a persisted copy without changing the original card', function () {
    $card = new EncryptedCard(
        number: 'encrypted-number',
        holder: 'encrypted-holder',
        expMonth: 'encrypted-month',
        expYear: 'encrypted-year',
        verificationValue: 'encrypted-cvv',
    );

    $persisted = $card->persisted();

    expect($persisted)
        ->not
        ->toBe($card)
        ->and($persisted->number)
        ->toBe($card->number)
        ->and($persisted->holder)
        ->toBe($card->holder)
        ->and($persisted->expMonth)
        ->toBe($card->expMonth)
        ->and($persisted->expYear)
        ->toBe($card->expYear)
        ->and($persisted->verificationValue)
        ->toBe($card->verificationValue)
        ->and($persisted->persist)
        ->toBeTrue()
        ->and($card->persist)
        ->toBeFalse();
});
