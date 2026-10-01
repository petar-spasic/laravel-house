<?php

declare(strict_types=1);

namespace PetarSpasic\LaravelHouse\Validation;

use RuntimeException;

final class ExportRefused extends RuntimeException
{
    public function __construct(
        public readonly string $form,
        public readonly string $field,
        public readonly string $rule,
        public readonly string $reason,
    ) {
        parent::__construct("{$form} › {$field} › {$rule}: {$reason}");
    }
}
