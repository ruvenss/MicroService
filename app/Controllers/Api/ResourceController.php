<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\ProblemDetails;
use App\Libraries\QueryParser;
use App\Libraries\RequestContext;
use App\Libraries\ResourceDefinition;
use App\Libraries\ResourceRegistry;
use App\Libraries\ResponseEnvelope;
use App\Models\GenericResourceModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Generic CRUD controller. One class serves every registered resource; the
 * resource slug arrives as the first route segment and is resolved against the
 * registry. Unknown slugs return a neutral 404 — indistinguishable from any
 * other not-found, so the registry contents are not probeable.
 */
class ResourceController extends BaseController
{
    public function index(string $slug): ResponseInterface
    {
        $definition = $this->resolve($slug);
        if ($definition === null) {
            return $this->notFound();
        }

        $spec = QueryParser::parse($this->request->getGet() ?? [], $definition);
        if (! $spec->isValid()) {
            return $this->problem(400, 'Invalid query parameters.', ['errors' => $spec->errors]);
        }

        $model = $this->model($definition);
        $this->applyFilters($model, $spec->filters);

        $perPage = $this->perPage($definition);
        $page    = max((int) ($this->request->getGet('page') ?? 1), 1);
        $total   = $model->countAllResults(false);

        [$column, $direction] = $this->sort($definition);
        $model->orderBy($column, $direction);
        if ($spec->fields !== null) {
            $model->select($spec->fields);
        }
        $rows = $model->findAll($perPage, ($page - 1) * $perPage);

        $rows = array_map(fn (array $row): array => $this->hide($row, $definition), $rows);

        return $this->response->setJSON(ResponseEnvelope::collection($rows, $page, $perPage, $total));
    }

    public function show(string $slug, string $id): ResponseInterface
    {
        $definition = $this->resolve($slug);
        if ($definition === null) {
            return $this->notFound();
        }

        $spec = QueryParser::parse($this->request->getGet() ?? [], $definition);
        if (! $spec->isValid()) {
            return $this->problem(400, 'Invalid query parameters.', ['errors' => $spec->errors]);
        }

        $model = $this->model($definition);
        if ($spec->fields !== null) {
            $model->select($spec->fields);
        }
        $row = $model->find($id);
        if ($row === null) {
            return $this->notFound();
        }

        return $this->response->setJSON(ResponseEnvelope::wrap($this->hide($row, $definition)));
    }

    public function create(string $slug): ResponseInterface
    {
        $definition = $this->resolve($slug);
        if ($definition === null) {
            return $this->notFound();
        }

        $data = $this->request->getJSON(true);
        if (! is_array($data)) {
            return $this->problem(400, 'Request body must be a JSON object.');
        }

        $errors = $this->validateAgainst($data, $definition->createRules);
        if ($errors !== []) {
            return $this->validationProblem($errors);
        }

        $model = $this->model($definition);
        $id    = $model->insert($this->onlyFillable($data, $definition), true);
        $row   = $model->find($id);

        return $this->response
            ->setStatusCode(201)
            ->setHeader('Location', site_url("api/v1/{$slug}/{$id}"))
            ->setJSON(ResponseEnvelope::wrap($this->hide((array) $row, $definition)));
    }

    public function update(string $slug, string $id): ResponseInterface
    {
        $definition = $this->resolve($slug);
        if ($definition === null) {
            return $this->notFound();
        }

        $model = $this->model($definition);
        if ($model->find($id) === null) {
            return $this->notFound();
        }

        $data = $this->request->getJSON(true);
        if (! is_array($data)) {
            return $this->problem(400, 'Request body must be a JSON object.');
        }

        $errors = $this->validateAgainst($data, $definition->updateRules);
        if ($errors !== []) {
            return $this->validationProblem($errors);
        }

        $model->update($id, $this->onlyFillable($data, $definition));

        return $this->response->setJSON(ResponseEnvelope::wrap($this->hide((array) $model->find($id), $definition)));
    }

    public function delete(string $slug, string $id): ResponseInterface
    {
        $definition = $this->resolve($slug);
        if ($definition === null) {
            return $this->notFound();
        }

        $model = $this->model($definition);
        if ($model->find($id) === null) {
            return $this->notFound();
        }

        $model->delete($id);

        return $this->response->setStatusCode(204);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function resolve(string $slug): ?ResourceDefinition
    {
        return ResourceRegistry::instance()->get($slug);
    }

    private function model(ResourceDefinition $definition): GenericResourceModel
    {
        return (new GenericResourceModel())->forResource($definition);
    }

    /**
     * Apply parsed filters to the model's query builder. Columns are already
     * allow-listed by the parser; values are bound by the builder.
     *
     * @param list<array{column: string, operator: string, value: mixed}> $filters
     */
    private function applyFilters(GenericResourceModel $model, array $filters): void
    {
        foreach ($filters as $filter) {
            $column = $filter['column'];
            $value  = $filter['value'];

            match ($filter['operator']) {
                'ne'    => $model->where("{$column} !=", $value),
                'gt'    => $model->where("{$column} >", $value),
                'gte'   => $model->where("{$column} >=", $value),
                'lt'    => $model->where("{$column} <", $value),
                'lte'   => $model->where("{$column} <=", $value),
                'like'  => $model->like($column, is_array($value) ? implode(',', $value) : (string) $value),
                'in'    => $model->whereIn($column, is_array($value) ? $value : [$value]),
                default => $model->where($column, $value), // 'eq'
            };
        }
    }

    private function perPage(ResourceDefinition $definition): int
    {
        $requested = (int) ($this->request->getGet('perPage') ?? $definition->perPageDefault);

        return max(1, min($requested, $definition->perPageMax));
    }

    /**
     * @return array{0: string, 1: string} [column, direction]
     */
    private function sort(ResourceDefinition $definition): array
    {
        $candidate = (string) ($this->request->getGet('sort') ?? '');
        if ($candidate === '') {
            $candidate = $definition->defaultSort;
        }

        $column = ltrim($candidate, '-+');
        if (! in_array($column, $definition->sortable, true)) {
            $candidate = $definition->defaultSort;
            $column    = ltrim($candidate, '-+');
        }

        return [$column, str_starts_with($candidate, '-') ? 'DESC' : 'ASC'];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function onlyFillable(array $data, ResourceDefinition $definition): array
    {
        return array_intersect_key($data, array_flip($definition->fillable));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function hide(array $row, ResourceDefinition $definition): array
    {
        return $definition->hidden === [] ? $row : array_diff_key($row, array_flip($definition->hidden));
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules
     *
     * @return array<string, string> field => message (empty when valid)
     */
    private function validateAgainst(array $data, array $rules): array
    {
        if ($rules === []) {
            return [];
        }

        $validation = service('validation');
        $validation->setRules($rules);

        return $validation->run($data) ? [] : $validation->getErrors();
    }

    private function notFound(): ResponseInterface
    {
        return $this->problem(404, null);
    }

    /**
     * @param array<string, string> $errors
     */
    private function validationProblem(array $errors): ResponseInterface
    {
        return $this->problem(422, 'One or more fields are invalid.', ['errors' => $errors]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function problem(int $status, ?string $detail, array $extra = []): ResponseInterface
    {
        $body = ProblemDetails::make($status, $detail, ['requestId' => RequestContext::id()] + $extra);

        return $this->response
            ->setStatusCode($status)
            ->setBody((string) json_encode($body))
            ->setContentType('application/problem+json');
    }
}
