<?php

declare(strict_types=1);

namespace IamAdty\Empira\Validation;

use IamAdty\Empira\Exception;

/**
 * Thrown when input is not acceptable, carrying the reasons.
 *
 * The exception is the "stop and answer 422" half of validation, for the code
 * that would rather not build a response by hand:
 *
 *     throw new ValidationException(Validator::make($input, $rules));
 *
 * A status other than 422 is there because not every validation failure is a
 * bad request — a form that conflicts with a version check might want 409 —
 * but 422 is the default because that is nearly always the answer.
 *
 *     catch (ValidationException $e) {
 *         return Response::json($e->errors(), $e->status);
 *     }
 */
final class ValidationException extends Exception
{
    public function __construct(
        private readonly ErrorBag $errors,
        public readonly int $status = 422,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : self::summarise($errors));
    }

    /**
     * Everything that failed, ready to render or to serialise.
     */
    public function errors(): ErrorBag
    {
        return $this->errors;
    }

    private static function summarise(ErrorBag $errors): string
    {
        $fields = array_keys($errors->all());

        return $fields === []
            ? 'Validation failed.'
            : 'Validation failed for: ' . implode(', ', $fields) . '.';
    }
}