<?php

namespace PetarSpasic\LaravelHouse\Kanban\Http;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Changed;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Conflict;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\Invalid;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\KanbanException;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\LockTimeout;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\NotFound;
use PetarSpasic\LaravelHouse\Kanban\Store\Exceptions\PolicyRefused;
use Symfony\Component\HttpFoundation\Response;

final class Api
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public static function json(array $data, int $status = 200, array $headers = []): JsonResponse
    {
        return response()->json($data, $status, $headers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Runs an action and turns the board's refusals into `{message, details}` with the status the page script acts on:
     * 409 the card changed since it was read (with the fresh card from $onConflict), 422 refused or invalid, 404, 503 busy.
     *
     * @param  Closure(): Response  $action
     * @param  (Closure(): array<string, mixed>)|null  $onConflict
     */
    public static function run(Closure $action, ?Closure $onConflict = null): Response
    {
        try {
            return $action();
        } catch (ValidationException $e) {
            return self::json(['message' => 'Not saved', 'details' => $e->validator->errors()->all()], 422);
        } catch (Changed $e) {
            try {
                $fresh = $onConflict ? $onConflict() : [];
            } catch (NotFound $gone) {
                return self::json(['message' => $gone->getMessage(), 'details' => []], 404);
            }

            return self::json(['message' => $e->getMessage(), 'details' => $e->details] + $fresh, 409);
        } catch (Conflict $e) {
            // a rebase or merge in the board's repository, not a stale card: the message says what to do
            return self::json(['message' => $e->getMessage(), 'details' => $e->details], 503);
        } catch (NotFound $e) {
            return self::json(['message' => $e->getMessage(), 'details' => []], 404);
        } catch (LockTimeout $e) {
            return self::json(['message' => $e->getMessage(), 'details' => []], 503);
        } catch (KanbanException $e) {
            return self::json(['message' => $e->getMessage(), 'details' => $e->details], $e instanceof Invalid || $e instanceof PolicyRefused ? 422 : 500);
        }
    }
}
