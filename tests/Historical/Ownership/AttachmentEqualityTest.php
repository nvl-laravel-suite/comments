<?php

declare(strict_types=1);
use Nvl\Comments\Actions\AttachCommentMediaAction;
use Nvl\Comments\Data\CommentActorData;
use Nvl\Comments\Tests\Fixtures\OwnershipActor;
use Nvl\Comments\Tests\Fixtures\OwnershipCommentScenario;
use Nvl\Comments\Tests\Fixtures\OwnershipCompany;
use Nvl\Comments\Tests\OwnershipMediaTestCase;
use Nvl\Media\Models\Media;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(OwnershipMediaTestCase::class);
it('rejects global media storage for an owned comment attachment', function (): void {
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(7), OwnershipActor::firstOrFail(), function (): void {
        $comment = OwnershipCommentScenario::comment();
        $media = Media::factory()->create(['is_public' => false, 'mime_type' => 'application/pdf']);
        expect(fn () => app(AttachCommentMediaAction::class)->execute($comment, $media, CommentActorData::system()))->toThrow(OwnershipViolation::class);
    });
});
