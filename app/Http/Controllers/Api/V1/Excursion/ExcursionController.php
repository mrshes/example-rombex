<?php

namespace App\Http\Controllers\Api\V1\Excursion;

use App\Http\Requests\Http\Controllers\Api\V1\Excursion\ExcursionController\GetRequest;
use App\Http\Requests\Http\Controllers\Api\V1\Excursion\ExcursionController\StoreRequest;
use App\Http\Requests\V1\Excursion\ExcursionStoreRequest;
use App\Services\Excursion\ExcursionService;
use App\Models\Comment;
use App\Models\Excursion;
use App\Models\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

use Illuminate\Support\Facades\DB;

/**
 * @group Управление экскурсиями
 */
class ExcursionController extends Controller
{

    /**
     * @var Excursion
     */
    protected $model;

    protected $service;

    public function __construct(Excursion $model)
    {
        $this->model = $model;
        $this->service = new ExcursionService();
    }

    /**
     * @return array
     */
    protected function relations($relations = []): array
    {
        return array_merge([
            'maps',
            'images',
            'times',
            'comments.user' => function ($query) {
                $query->latest()->first();
            },
            'user' => function ($q) {
                $q->select('id');
                $q->with('organization:id,user_id,inn,name,ogrn,kpp,organization_type');
            },
        ], $relations);
    }

    /**
     * Получить экскурсии
     * @queryParam user_id integer.
     * @param Request $request
     * @param Excursion $excursion
     * @return mixed
     */
    public function index(GetRequest $request)
    {
        $data = $request->validated();
        $data['hide_blocked'] = true;
        return $this->service->getPaginateEntries($data);

    }

    /**
     * Output of user excursion
     * @param Request $request
     * @return mixed
     */
    public function getUserExcursions(GetRequest $request)
    {
        $data = $request->validated();
        $data['user_id'] = $request->user()->id;
        $data['with_hidden'] = true;
        return $this->service->getPaginateEntries($data);
    }


    /**
     * Показать по slug для авторизованного пользователя
     * @queryParam $slug string. Example: test;
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function showUserExcursion(Request $request, $slug): \Illuminate\Http\Response
    {
        $data = $request->validate([
            'with_hidden' => 'sometimes|boolean',
            'type' => 'sometimes|string',
        ]);
        $data['slug'] = $slug;
        $data['user_id'] = $request->user()->id;
        $record = $this->service->fetch($data)->with($this->relations(['user.role', 'comments.user', 'times.points.map']))->firstOrFail();
        return response()->json($record);
    }


    /**
     * Регистрации экскурсии
     * @param Request $request
     * @param Excursion $excursion
     * @param File $fileModel
     * @return mixed
     */
    public function store(StoreRequest $request)
    {
        return DB::transaction(function () use ($request) {
            $data = $request->validated();
            $data['user_id'] = $request->user()->id;
            $record = $this->service->updateOrCreate($data);
            $record->setStatus(0);
            return response()->json($record);
        });
    }

    /**
     * Показать по id
     * @queryParam $id string. Example: test;
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function showById($id, Request $request)
    {
        $record = $this->model->whereId($id)->with($this->relations(['user.role', 'comments.user', 'times.points.map']));
        if ($request->has('type')) {
            $record->where('type', $request->input('type'));
        }
        $record = $record->firstOrFail();
        return response()->json($record);
    }

    /**
     * Показать по slug
     * @queryParam $slug string. Example: test;
     * @param int $id
     * @return JsonResponse
     */
    public function show(Request $request, $slug): JsonResponse
    {
        $data = $request->validate([
            'hide_blocked' => 'sometimes|boolean',
            'type' => 'sometimes|array',
        ]);
        $data['slug'] = $slug;
        $entry = $this->service->fetch($data)->with($this->relations(['user.role', 'comments.user', 'times.points.map']))->firstOrFail();;
        return response()->json($entry);
    }

    /**
     * Обновленеие экскурсии
     * @queryParam name required text
     * @queryParam name text
     * @queryParam description text
     * @queryParam region json
     * @queryParam city json
     * @queryParam amount decimal
     * @queryParam date_start date
     * @queryParam date_end date
     * @queryParam day date
     * @queryParam time_start time
     * @queryParam time_end time
     * @queryParam duration text
     * @queryParam body text
     * @queryParam age integer
     * @queryParam type int
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function update(StoreRequest $request, Excursion $excursion): \Illuminate\Http\JsonResponse
    {
        $data = $request->validated();
        return DB::transaction(function () use ($data, $excursion) {
            $record = $excursion->getService()->updateOrCreate($data);
            return response()->json($record);
        });
    }

    /**
     * Удалите указанный ресурс из хранилища.
     * @bodyParam $id int. Example: 1;
     * @param $id
     * @return mixed
     */
    public function destroy(Excursion $excursion)
    {
        $destroy = $excursion->delete();
        return response()->json($destroy);
    }

    /**
     * Добавление комментария для экскурсии
     * @bodyParam excursion_id int required. Excursion id
     * @bodyParam like int required
     * @bodyParam body text required
     * @param Request $request
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     * @response {"like":"3","body":"test","id":2}
     */
    public function commentStore(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'excursion_id' => 'required|integer',
            'like' => 'required|integer',
            'body' => 'required|string',
        ]);
        $user = $request->user();
        $record = $this->model->findOrFail($data['excursion_id']);
        $comment = (new Comment())->store($record, $data['like'], $data['body'], $user->id);

        return response()->json($comment);
    }

    /**
     * Поиск по названию экскурсии
     * @param Request $request
     * @return mixed
     */
    public function searchByName(Request $request)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|nullable',
        ]);
        $query = $this->model->fetch($data)->select(['id', 'name']);
        return $query->paginate($request->input('count', 10));
    }


    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function setStatus(Request $request)
    {
        $data = $request->validate([
            'recordId' => 'numeric|required',
            'statusId' => 'numeric|required',
        ]);
        $record = $this->model->findOrFail($data['recordId'])->setStatus($data['statusId']);
        return response()->json([
            'id' => $record->id,
            'status' => $record->status,
        ]);
    }

    /**
     * Возвращаем диапазон возможных цен на мероприятия
     * @param Request $request
     * @return mixed
     */
    public function getPriceRange(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|string|max:3',
        ]);

        $priceRange = [
            (int)$this->model->where($data)->where('status', 'ACTIVE')->min('price_adult'),
            (int)$this->model->where($data)->where('status', 'ACTIVE')->max('price_adult'),
        ];
        return response()->json($priceRange);
    }
}
