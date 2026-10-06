<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Nvl\Comments\Actions\AnonymizeCommentAction;
use Nvl\Comments\Actions\AttachCommentMediaAction;
use Nvl\Comments\Actions\CreateCommentAction;
use Nvl\Comments\Actions\CreateRichCommentAction;
use Nvl\Comments\Actions\DeleteCommentAction;
use Nvl\Comments\Actions\DeleteLatestTargetCommentAction;
use Nvl\Comments\Actions\DetachCommentMediaAction;
use Nvl\Comments\Actions\FindLatestTargetCommentAction;
use Nvl\Comments\Actions\GetCommentAction;
use Nvl\Comments\Actions\ListCommentAttachmentsAction;
use Nvl\Comments\Actions\ListCommentReportsAction;
use Nvl\Comments\Actions\ListCommentRevisionsAction;
use Nvl\Comments\Actions\ListCommentsAction;
use Nvl\Comments\Actions\ListModerationCommentsAction;
use Nvl\Comments\Actions\ListOwnerCommentSummariesAction;
use Nvl\Comments\Actions\ListTargetCommentReportsAction;
use Nvl\Comments\Actions\ModerateCommentAction;
use Nvl\Comments\Actions\ReportCommentAction;
use Nvl\Comments\Actions\ResolveCommentMentionsAction;
use Nvl\Comments\Actions\ResolveCommentReportAction;
use Nvl\Comments\Actions\RestoreCommentAction;
use Nvl\Comments\Actions\RestoreCommentRevisionAction;
use Nvl\Comments\Actions\SetCommentReactionAction;
use Nvl\Comments\Actions\SuggestCommentMentionResourcesAction;
use Nvl\Comments\Actions\UpdateCommentAction;
use Nvl\Comments\Actions\UpdateRichCommentAction;
use Nvl\Comments\Contracts\AnonymizeCommentContract;
use Nvl\Comments\Contracts\AttachCommentMediaContract;
use Nvl\Comments\Contracts\CreateCommentContract;
use Nvl\Comments\Contracts\CreateRichCommentContract;
use Nvl\Comments\Contracts\DeleteCommentContract;
use Nvl\Comments\Contracts\DeleteLatestTargetCommentContract;
use Nvl\Comments\Contracts\DetachCommentMediaContract;
use Nvl\Comments\Contracts\FindLatestTargetCommentContract;
use Nvl\Comments\Contracts\GetCommentContract;
use Nvl\Comments\Contracts\ListCommentAttachmentsContract;
use Nvl\Comments\Contracts\ListCommentReportsContract;
use Nvl\Comments\Contracts\ListCommentRevisionsContract;
use Nvl\Comments\Contracts\ListCommentsContract;
use Nvl\Comments\Contracts\ListModerationCommentsContract;
use Nvl\Comments\Contracts\ListOwnerCommentSummariesContract;
use Nvl\Comments\Contracts\ListTargetCommentReportsContract;
use Nvl\Comments\Contracts\ModerateCommentContract;
use Nvl\Comments\Contracts\ReportCommentContract;
use Nvl\Comments\Contracts\ResolveCommentMentionsContract;
use Nvl\Comments\Contracts\ResolveCommentReportContract;
use Nvl\Comments\Contracts\RestoreCommentContract;
use Nvl\Comments\Contracts\RestoreCommentRevisionContract;
use Nvl\Comments\Contracts\SetCommentReactionContract;
use Nvl\Comments\Contracts\SuggestCommentMentionResourcesContract;
use Nvl\Comments\Contracts\UpdateCommentContract;
use Nvl\Comments\Contracts\UpdateRichCommentContract;
use Nvl\Comments\Providers\CommentsServiceProvider;
use Nvl\Comments\Tests\TestCase;

if (! in_array(dirname(__DIR__).'/Pest.php', get_included_files(), true)) {
    uses(TestCase::class);
}

/** @return list<array{class-string, class-string}> */
function nvlConsumerBindingsForComments(): array
{
    return [
        [AnonymizeCommentContract::class, AnonymizeCommentAction::class],
        [AttachCommentMediaContract::class, AttachCommentMediaAction::class],
        [CreateCommentContract::class, CreateCommentAction::class],
        [CreateRichCommentContract::class, CreateRichCommentAction::class],
        [DeleteCommentContract::class, DeleteCommentAction::class],
        [DeleteLatestTargetCommentContract::class, DeleteLatestTargetCommentAction::class],
        [DetachCommentMediaContract::class, DetachCommentMediaAction::class],
        [FindLatestTargetCommentContract::class, FindLatestTargetCommentAction::class],
        [GetCommentContract::class, GetCommentAction::class],
        [ListCommentAttachmentsContract::class, ListCommentAttachmentsAction::class],
        [ListCommentReportsContract::class, ListCommentReportsAction::class],
        [ListCommentRevisionsContract::class, ListCommentRevisionsAction::class],
        [ListCommentsContract::class, ListCommentsAction::class],
        [ListModerationCommentsContract::class, ListModerationCommentsAction::class],
        [ListOwnerCommentSummariesContract::class, ListOwnerCommentSummariesAction::class],
        [ListTargetCommentReportsContract::class, ListTargetCommentReportsAction::class],
        [ModerateCommentContract::class, ModerateCommentAction::class],
        [ReportCommentContract::class, ReportCommentAction::class],
        [ResolveCommentMentionsContract::class, ResolveCommentMentionsAction::class],
        [ResolveCommentReportContract::class, ResolveCommentReportAction::class],
        [RestoreCommentContract::class, RestoreCommentAction::class],
        [RestoreCommentRevisionContract::class, RestoreCommentRevisionAction::class],
        [SetCommentReactionContract::class, SetCommentReactionAction::class],
        [SuggestCommentMentionResourcesContract::class, SuggestCommentMentionResourcesAction::class],
        [UpdateCommentContract::class, UpdateCommentAction::class],
        [UpdateRichCommentContract::class, UpdateRichCommentAction::class],
    ];
}

test('published workflow contracts retain native signatures attributes and generic documentation', function (): void {
    $genericDocumentation = static function (string|false $documentation): array {
        if ($documentation === false) {
            return [];
        }
        preg_match_all('/@param\s+([^\r\n]+?)\s+(\$[A-Za-z_][A-Za-z0-9_]*)\b/', $documentation, $parameters, PREG_SET_ORDER);
        $result = [];
        foreach ($parameters as $parameter) {
            $type = preg_replace('/\s+/', '', $parameter[1]);
            if (str_contains($type, '<') || str_contains($type, '{') || str_contains($type, '[]')) {
                $result['@param'.$parameter[2]] = $type;
            }
        }
        if (preg_match('/@return\s+([^\r\n]+)/', $documentation, $return) === 1) {
            $type = '';
            $depth = 0;
            foreach (str_split($return[1]) as $character) {
                if (preg_match('/\s/', $character) === 1 && $depth === 0) {
                    break;
                }
                if (str_contains('<{([', $character)) {
                    $depth++;
                } elseif (str_contains('>})]', $character)) {
                    $depth--;
                }
                if (preg_match('/\s/', $character) !== 1) {
                    $type .= $character;
                }
            }
            if (str_contains($type, '<') || str_contains($type, '{') || str_contains($type, '[]')) {
                $result['@return'] = $type;
            }
        }

        return $result;
    };

    foreach (nvlConsumerBindingsForComments() as [$contract, $implementation]) {
        $interface = new ReflectionClass($contract);
        $concrete = new ReflectionClass($implementation);
        expect($interface->isInterface())->toBeTrue()
            ->and($concrete->implementsInterface($contract))->toBeTrue();
        foreach ($interface->getMethods() as $method) {
            $native = $concrete->getMethod($method->getName());
            $return = (string) $method->getReturnType();

            $publishedTypes = $genericDocumentation($method->getDocComment());
            foreach ($genericDocumentation($native->getDocComment()) as $tag => $type) {
                expect($publishedTypes[$tag] ?? null)->toBe($type);
            }

            expect($native->isPublic())->toBeTrue()
                ->and($native->isStatic())->toBeFalse()
                ->and(count($method->getParameters()))->toBe(count($native->getParameters()));
            if ($return !== 'self') {
                expect((string) $native->getReturnType())->toBe($return);
            } else {
                $nativeReturn = (string) $native->getReturnType();
                expect(is_a(in_array($nativeReturn, ['self', 'static'], true) ? $native->getDeclaringClass()->getName() : $nativeReturn, $contract, true))->toBeTrue();
            }
            foreach ($method->getParameters() as $position => $parameter) {
                $actual = $native->getParameters()[$position];
                expect($actual->getName())->toBe($parameter->getName())
                    ->and((string) $actual->getType())->toBe((string) $parameter->getType())
                    ->and($actual->isVariadic())->toBe($parameter->isVariadic())
                    ->and($actual->isPassedByReference())->toBe($parameter->isPassedByReference())
                    ->and($actual->isDefaultValueAvailable())->toBe($parameter->isDefaultValueAvailable())
                    ->and(array_map(static fn (ReflectionAttribute $attribute): array => [$attribute->getName(), $attribute->getArguments()], $actual->getAttributes()))
                    ->toBe(array_map(static fn (ReflectionAttribute $attribute): array => [$attribute->getName(), $attribute->getArguments()], $parameter->getAttributes()));
                if ($parameter->isDefaultValueAvailable()) {
                    expect($actual->getDefaultValue())->toEqual($parameter->getDefaultValue());
                }
            }
        }
    }
});

test('native provider defaults resolve each workflow while preserving late host substitutes', function (): void {
    foreach (nvlConsumerBindingsForComments() as [$contract, $implementation]) {
        expect($this->app->bound($contract))->toBeTrue()
            ->and($this->app->make($contract))->toBeInstanceOf($implementation);
        $host = Mockery::mock($contract);
        $this->app->instance($contract, $host);
        expect($this->app->make($contract))->toBe($host);
    }
});

test('provider registration preserves early interface bindings in a second native application', function (): void {
    $consumer = new Application($this->app->basePath());
    $consumer->instance('config', new Repository($this->app->make('config')->all()));
    $consumer->instance('env', 'testing');
    $consumer->register(FilesystemServiceProvider::class);
    $hosts = [];
    foreach (nvlConsumerBindingsForComments() as [$contract]) {
        $hosts[$contract] = Mockery::mock($contract);
        $consumer->instance($contract, $hosts[$contract]);
    }
    try {
        $consumer->register(CommentsServiceProvider::class);
        foreach ($hosts as $contract => $host) {
            expect($consumer->make($contract))->toBe($host);
        }
    } finally {
        Container::setInstance($this->app);
        $consumer->flush();
    }
});
