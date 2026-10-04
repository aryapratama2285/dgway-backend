<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Shared\Idempotency\CommandResult;
use App\Application\Shared\Idempotency\IdempotentCommandExecutor;
use App\Application\Shared\Query\Page;
use App\Application\Shared\Query\PageRequest;
use App\Application\Shared\Query\StatusFilter;
use App\Application\Shared\Security\ActorContext;
use App\Domain\Shared\Identity\Actor;
use App\Domain\Shared\Model\AggregateRoot;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireIdempotencyKey;
use App\Http\Support\IdempotentResponse;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Base for API controllers. Provides the plumbing every endpoint shares, so
 * controllers stay thin: actor resolution, idempotent command execution,
 * paging/status parameters and the response envelope.
 */
abstract class ApiController extends Controller
{
    public function __construct(
        protected readonly IdempotentCommandExecutor $executor,
        protected readonly ActorContext $actors,
    ) {
    }

    protected function actor(): Actor
    {
        return $this->actors->current();
    }

    /**
     * Runs a mutating use case at most once per Idempotency-Key.
     * The "same request" is defined by the command name + route parameters
     * + request body, so a retry with the same key replays the original
     * response; a different body with the same key is rejected (409).
     *
     * @param  Closure(Actor): object  $useCase  returns an aggregate or document
     * @param  Closure(object): array<string, mixed>  $present
     */
    protected function command(Request $request, string $commandName, Closure $useCase, Closure $present, int $status = 200, ?string $resourceType = null): JsonResponse
    {
        $actor = $this->actor();
        $payload = ['route' => $request->route()?->parameters() ?? [], 'body' => $request->all()];

        $outcome = $this->executor->execute(
            $actor,
            $request->attributes->get(RequireIdempotencyKey::ATTRIBUTE),
            $commandName,
            $payload,
            function () use ($useCase, $present, $actor, $status, $resourceType): CommandResult {
                $result = $useCase($actor);
                $id = $result instanceof AggregateRoot ? $result->id()->value : ($result->id->value ?? null);

                return new CommandResult($status, ['data' => $present($result)], $resourceType, $id);
            },
        );

        // one structured line per command: request_id, actor_user_id and idempotency_key come from the shared log context
        Log::info('api.command', [
            'command' => $commandName,
            'status' => $outcome->result->statusCode,
            'replayed' => $outcome->replayed,
            'resource_type' => $outcome->result->resourceType,
            'resource_id' => $outcome->result->resourceId,
        ]);

        return IdempotentResponse::from($outcome);
    }

    protected function status(Request $request, StatusFilter $default = StatusFilter::ALL): StatusFilter
    {
        return StatusFilter::tryFrom((string) $request->query('status', $default->value)) ?? $default;
    }

    protected function pageRequest(Request $request): PageRequest
    {
        return new PageRequest((int) $request->query('page', 1), (int) $request->query('per_page', 25));
    }

    /**
     * @param  Page<mixed>  $page
     * @param  Closure(mixed): array<string, mixed>  $present
     */
    protected function paged(Page $page, Closure $present): JsonResponse
    {
        return new JsonResponse([
            'data' => array_map($present, $page->items),
            'meta' => [
                'page' => $page->request->page,
                'per_page' => $page->request->perPage,
                'total' => $page->total,
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /** @param list<mixed> $items */
    protected function listed(array $items, Closure $present): JsonResponse
    {
        return new JsonResponse(['data' => array_map($present, $items)]);
    }

    /** @param array<string, mixed> $data */
    protected function single(array $data): JsonResponse
    {
        return new JsonResponse(['data' => $data]);
    }
}
