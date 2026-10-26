<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Nvl\Comments\Data\CommentActorData;

it('accepts only canonical anonymous identified and system actor shapes', function (): void {
    $maximumType = str_repeat('Ж', 100);
    $maximumIdentifier = str_repeat('界', 255);
    $anonymous = CommentActorData::anonymous();
    $identified = new CommentActorData($maximumType, $maximumIdentifier);
    $preserved = new CommentActorData(' Member ', ' 0042 ');
    $system = CommentActorData::system();

    expect($anonymous->type)->toBeNull()
        ->and($anonymous->id)->toBeNull()
        ->and($anonymous->system)->toBeFalse()
        ->and($identified->type)->toBe($maximumType)
        ->and($identified->id)->toBe($maximumIdentifier)
        ->and($identified->system)->toBeFalse()
        ->and($preserved->type)->toBe(' Member ')
        ->and($preserved->id)->toBe(' 0042 ')
        ->and($system->type)->toBe('system')
        ->and($system->id)->toBeNull()
        ->and($system->system)->toBeTrue();
});

it('rejects structurally invalid actor identities', function (
    ?string $type,
    ?string $identifier,
    bool $system,
    string $message,
): void {
    expect(fn (): CommentActorData => new CommentActorData(
        $type,
        $identifier,
        $system,
    ))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'type without identifier' => [
        'member',
        null,
        false,
        'Comment actor type and identifier must both be null for anonymous actors or both be present for identified actors.',
    ],
    'identifier without type' => [
        null,
        '42',
        false,
        'Comment actor type and identifier must both be null for anonymous actors or both be present for identified actors.',
    ],
    'system flag without system type' => [
        null,
        null,
        true,
        'System comment actors must use type [system] with a null identifier.',
    ],
    'system flag with identified shape' => [
        'system',
        '42',
        true,
        'System comment actors must use type [system] with a null identifier.',
    ],
    'reserved type without system flag' => [
        'system',
        '42',
        false,
        'The [system] comment actor type is reserved for system actors.',
    ],
]);

it('rejects invalid blank or oversized actor identity text', function (
    string $type,
    string $identifier,
    string $message,
): void {
    expect(fn (): CommentActorData => new CommentActorData(
        $type,
        $identifier,
    ))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'blank type' => [
        "\t\n",
        '42',
        'Comment actor type must not be blank.',
    ],
    'unicode blank type' => [
        "\u{2003}",
        '42',
        'Comment actor type must not be blank.',
    ],
    'blank identifier' => [
        'member',
        '   ',
        'Comment actor identifier must not be blank.',
    ],
    'invalid UTF-8 type' => [
        "\xC3\x28",
        '42',
        'Comment actor type must be valid UTF-8.',
    ],
    'invalid UTF-8 identifier' => [
        'member',
        "\xC3\x28",
        'Comment actor identifier must be valid UTF-8.',
    ],
    'oversized multibyte type' => [
        str_repeat('Ж', 101),
        '42',
        'Comment actor type must not exceed 100 characters.',
    ],
    'oversized multibyte identifier' => [
        'member',
        str_repeat('界', 256),
        'Comment actor identifier must not exceed 255 characters.',
    ],
]);

it('converts only integer and string authentication identifiers', function (): void {
    $integerActor = CommentActorData::fromAuthenticatable(new GenericUser(['id' => 42]));
    $stringActor = CommentActorData::fromAuthenticatable(new GenericUser(['id' => 'member:0042']));

    expect($integerActor->type)->toBe(GenericUser::class)
        ->and($integerActor->id)->toBe('42')
        ->and($stringActor->type)->toBe(GenericUser::class)
        ->and($stringActor->id)->toBe('member:0042');

    foreach ([null, true, 42.5, [], new stdClass] as $identifier) {
        expect(fn (): CommentActorData => CommentActorData::fromAuthenticatable(
            new GenericUser(['id' => $identifier]),
        ))->toThrow(
            InvalidArgumentException::class,
            sprintf(
                'Authenticated comment actor [%s] must expose an integer or string identifier; [%s] returned.',
                GenericUser::class,
                get_debug_type($identifier),
            ),
        );
    }
});
