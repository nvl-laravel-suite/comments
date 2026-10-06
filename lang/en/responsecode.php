<?php

declare(strict_types=1);

return [
    'operation_failed' => 'The operation could not be completed.',
    'comment_target_not_found' => 'The comment target is unavailable.',
    'comment_attachment_delivery_unavailable' => 'Comment attachment delivery is unavailable.',
    'comment_mutation_busy' => 'Another comment update is in progress. Retry shortly.',
    'comment_idempotency_conflict' => 'The idempotency key was used for another comment request.',
    'stale_comment' => 'The comment changed after it was read.',
    'invalid_comment_lifecycle' => 'The comment lifecycle transition is invalid.',
    'invalid_comment_mutation' => 'The comment update is invalid.',
    'invalid_configuration' => 'This capability is not configured correctly.',
    'batch_read_unavailable' => 'The requested batch read is unavailable.',
];
