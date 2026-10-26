<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\User;
use Nvl\Comments\Enums\CommentAbility;
use Nvl\Comments\Services\CommentActor;
use Nvl\Comments\Services\ConfiguredCommentAuthorization;
use Nvl\Support\Enums\PublicActor;
use Nvl\Support\Enums\SystemActor;

it('does not give a background actor implicit comment authority', function (): void {
    $policy = new ConfiguredCommentAuthorization;

    expect($policy->allows(CommentAbility::Create, SystemActor::Background))->toBeFalse();
    expect($policy->allows(CommentAbility::Moderate, SystemActor::Background))->toBeFalse();
    expect($policy->allows(CommentAbility::List, SystemActor::Background))->toBeFalse();
    expect($policy->allows(CommentAbility::Moderate, PublicActor::Anonymous))->toBeFalse();
});

it('requires a persisted canonical human actor', function (): void {
    CommentActor::from(new User);
})->throws(AuthorizationException::class);
