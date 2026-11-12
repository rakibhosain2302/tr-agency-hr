<?php

namespace Nit\AgraniHr\Http\Controllers\Api;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Nit\AgraniHr\Exceptions\HrException;

/**
 * List, add, change and delete the organisation's lookup data (departments, designations, employment types,
 * statuses). Subclasses name the model, fields and rules, and declare abilities in static $permissions.
 */
abstract class MasterDataController extends Controller
{
    /** @return class-string<Model> */
    abstract protected function model(): string;

    /** @return list<string> fields returned and written */
    abstract protected function fields(): array;

    /** @return array<string, mixed> */
    abstract protected function rules(?Model $record): array;

    /** Whether the record is still used (then it is not deleted: make it inactive instead). */
    abstract protected function inUse(Model $record): bool;

    protected function query(Request $request): Builder
    {
        return $this->model()::query()->orderBy('code');
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query($request)->get()->map(fn (Model $record): array => $this->present($record))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        // refresh(): the database defaults (is_active, is_system …) are part of the answer.
        $record = $this->model()::query()->create($this->prepare($request->validate($this->rules(null))))->refresh();

        return response()->json(['data' => $this->present($record)], 201);
    }

    public function update(Request $request, int $record): JsonResponse
    {
        $model = $this->model()::query()->findOrFail($record);
        $model->update($this->prepare($request->validate($this->rules($model))));

        return response()->json(['data' => $this->present($model)]);
    }

    public function destroy(int $record): Response
    {
        $model = $this->model()::query()->findOrFail($record);
        if ($this->inUse($model)) {
            throw HrException::invalid(__(':name is in use; make it inactive instead.', ['name' => $model->name]), 'in_use');
        }
        $model->delete();

        return response()->noContent();
    }

    /** @param  array<string, mixed>  $data */
    protected function prepare(array $data): array
    {
        return $data;
    }

    /** @return array<string, mixed> */
    protected function present(Model $record): array
    {
        return ['id' => $record->getKey()] + $record->only($this->fields());
    }
}
