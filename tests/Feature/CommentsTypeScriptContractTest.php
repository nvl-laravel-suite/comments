<?php

declare(strict_types=1);

use Nvl\Comments\Data\CommentMentionChangeData;
use Nvl\Comments\Data\CommentMentionData;
use Nvl\Comments\Data\CommentMentionResourceData;
use Nvl\Comments\Data\CommentMentionSuggestionData;
use Nvl\Comments\Data\Mutations\CreateCommentData;
use Nvl\Comments\Data\Mutations\ModerateCommentData;
use Nvl\Comments\Data\Mutations\ReportCommentData;
use Nvl\Comments\Enums\CommentFormat;
use Nvl\Comments\Enums\CommentStatus;
use Nvl\Comments\Enums\CommentVisibility;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

it('marks every backend-defaulted comment mutation field optional for TypeScript', function (): void {
    /** @var array<class-string, list<string>> $optionalProperties */
    $optionalProperties = [
        CreateCommentData::class => [
            'format',
            'visibility',
            'locale',
            'parentId',
            'tags',
            'metadata',
            'idempotencyKey',
        ],
        ModerateCommentData::class => ['reason', 'pinned'],
        ReportCommentData::class => ['details'],
    ];

    foreach ($optionalProperties as $class => $properties) {
        foreach ($properties as $property) {
            expect((new ReflectionProperty($class, $property))
                ->getAttributes(TypeScriptOptional::class))->toHaveCount(1);
        }
    }

    expect((new ReflectionProperty(CreateCommentData::class, 'body'))
        ->getAttributes(TypeScriptOptional::class))->toBe([])
        ->and((new ReflectionProperty(ModerateCommentData::class, 'status'))
            ->getAttributes(TypeScriptOptional::class))->toBe([])
        ->and((new ReflectionProperty(ModerateCommentData::class, 'expectedRevision'))
            ->getAttributes(TypeScriptOptional::class))->toBe([])
        ->and((new ReflectionProperty(ReportCommentData::class, 'reason'))
            ->getAttributes(TypeScriptOptional::class))->toBe([]);
});

it('retains the declared backend defaults for omitted mutation fields', function (): void {
    $create = CreateCommentData::validateAndCreate(['body' => 'Defaulted comment']);
    $moderate = ModerateCommentData::validateAndCreate([
        'status' => CommentStatus::Approved->value,
        'expectedRevision' => 1,
    ]);
    $report = ReportCommentData::validateAndCreate(['reason' => 'spam']);

    expect($create->format)->toBe(CommentFormat::Plain)
        ->and($create->visibility)->toBe(CommentVisibility::Public)
        ->and($create->locale)->toBeNull()
        ->and($create->parentId)->toBeNull()
        ->and($create->tags)->toBe([])
        ->and($create->metadata)->toBe([])
        ->and($create->idempotencyKey)->toBeNull()
        ->and($moderate->reason)->toBeNull()
        ->and($moderate->pinned)->toBeNull()
        ->and($report->details)->toBeNull();
});

it('exports every viewer and event mention contract to generated TypeScript', function (): void {
    foreach ([
        CommentMentionResourceData::class,
        CommentMentionSuggestionData::class,
        CommentMentionData::class,
        CommentMentionChangeData::class,
    ] as $class) {
        expect((new ReflectionClass($class))->getAttributes(TypeScript::class))
            ->toHaveCount(1);
    }

    expect((new ReflectionProperty(CommentMentionData::class, 'resourceId'))->getType())
        ->not->toBeNull()
        ->and((new ReflectionProperty(CommentMentionData::class, 'currentLabel'))->getType())
        ->not->toBeNull();
});
