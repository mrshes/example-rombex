<?php


namespace App\Services\Excursion;


use App\Models\AppConfig;
use App\Models\Excursion;
use App\Models\ExcursionTimePoint;
use App\Models\File;
use App\Services\BaseService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ExcursionService extends BaseService
{

    public function __construct(?Model $model = null)
    {
        $model = $model ?: new Excursion();
        parent::__construct($model);
    }

    /**
     * @return ExcursionRepository
     */
    protected function getRepository(): ExcursionRepository
    {
        return new ExcursionRepository();
    }

    /**
     * @param array $data
     * @return mixed
     */
    public function getPaginateEntries(array $data)
    {
        $limit = $data['limit'] ?? 15;
        $data['sort'] = $data['sort'] ?? 'new';
        $query = $this->fetch($data)->with([
            'images',
            'times.points.map',
        ]);
        return $query->paginate($limit);
    }


    /**
     * Поличить дни максимальной брони экскурсии
     * @return int
     */
    public function getDaysBookingByConfigKey(string $key): int
    {
        /** @var Excursion $excursion */
        $excursion = $this->getModel();
        $type = $excursion->subtype;
        $recordType = $excursion->type;
        $appConfigDays = AppConfig::getByKey($key);
        $excursionTypes = $excursion->getTypes();
        $excursionSubtypes = $excursion->getSubtypes();
        $days = 0; // default
        if ($recordType === $excursionTypes[2]) {
            $days = $appConfigDays['vip'];
        } else {
            if ($type == $excursionSubtypes[0]) { // vip
                $days = $appConfigDays['individual'];

            } elseif ($type == $excursionSubtypes[1]) { // group
                $days = $appConfigDays['group'];
            }
        }
        return (int)$days;
    }

    /**
     * Сохранение или обновление экскурсии
     * @param array $data
     * @return Model
     */
    public function updateOrCreate(array $data): Model
    {
        $times = null;
        if (isset($data['times'])) {
            $times = $data['times'];
            unset($data['times']);
        }

        $entry = $this->store($data);

        // Сохранение времени и точек сборов к ним
        if ($times) {
            $timesEntry = (new ExcursionTimeService())->storeExcTimes($entry, $times);
        }

        if (isset($data['images'])) {
            $this->storeExcImages($data['images']);
        }
        return $entry;
    }


    /**
     * Сохранение экскурсии
     * @param $data
     * @return Model
     */
    public function store(array $data): Model
    {
        $model = $this->getModel();

        $model->fill($data);
        // Сохраняем экскурсию без сеансов и точек
        $model->save();
        return $model;
    }

    public function storeExcImages(array $images)
    {
        $imagesIDs = [];
        $fileModel = new File();
        $excursion = $this->getModel();
        foreach ($images as $key => $image) {
            $file = $image['file'];
            if ($file instanceof UploadedFile) {
                $fileModel->store(get_class($excursion), $excursion->id, $excursion->getTable() . '/' . $excursion->id, $file, 'images', $key);
                array_push($imagesIDs, $fileModel->id);
            } elseif (is_numeric($file)) {
                $fileModel->setSort($file, $key);
                array_push($imagesIDs, $file);
            }
        }
        if (count($imagesIDs)) {
            $fileModel->deleteAll(get_class($excursion), $excursion->id, $imagesIDs);
        }
    }

    /**
     * Выборка
     * @param $data
     * @return mixed
     */
    public function fetch($data)
    {
        $query = $this->getModel()->newQuery();

        foreach ($data as $key => $value) {
            switch ($key) {
                case 'slug':
                    $query->whereSlug($value);
                    break;
                case 'name':
                    $query->where('name', 'LIKE', "%$value%");
                    break;
                case 'qr_code':
                    $query->whereHas('qr_code', function ($q) use ($value) {
                        $q->chack($value);
                    });
                    break;
                case 'subtype':
                    $query->whereIn('subtype', $value);
                    break;
                case 'type':
                    $query->whereIn('type', $value);
                    break;
                case 'for_someone':
                    $query->where(function (Builder $q) use ($key, $value) {
                        foreach ($value as $vKey) {
                            $q->orWhereJsonContains("props->{$key}->{$vKey}", true);
                        }
                    });
                    break;
                case 'kind':
                    $query->where(function (Builder $q) use ($key, $value) {
                        foreach ($value as $vKey) {
                            $q->orWhereJsonContains("props->{$key}->{$vKey}", true);
                        }
                    });
                    break;
                case 'move':
                    $query->where(function (Builder $q) use ($key, $value) {
                        foreach ($value as $vKey) {
                            if ($vKey) {
                                $q->orWhereJsonContains("props->{$key}->{$vKey}", true);
                            }
                        }
                    });
                    break;
                case 'location_type':
                    $query->whereIn('location_type', $value);
                    break;
                case 'languages':
                    $query->where(function (Builder $q) use ($key, $value) {
                        $value = json_decode($value, true);
                        foreach ($value as $vKey => $vVal) {
                            if ($vVal) {
                                $q->whereJsonContains('props->languages', [
                                    'text' => $vKey,
                                    'value' => $vVal,
                                ]);
                            }
                        }
                    });
                    break;
                case 'duration':
                    if ($value[0] !== "0" && $value[1] !== "6") {
                        $duration_start = Carbon::parse($this->getDurationTime($value[0]));
                        $duration_end = Carbon::parse($this->getDurationTime($value[1]));
                        $query->where(function ($q) use ($duration_start) {
                            $q->where('props->duration->hour', '>=', $duration_start->hour);
                            $q->where('props->duration->minute', '>=', $duration_start->minute);
                        });
                        $query->orWhere(function ($q) use ($duration_end) {
                            $q->where('props->duration->hour', '<=', $duration_end->hour);
                            $q->where('props->duration->minute', '<=', $duration_end->minute);
                        });
                    }
                    break;
                case 'price':
                    $query->whereBetween('price_adult', $value);
                    break;
                case 'date_start':
                    $date_start = $data['date_start'] ?? date('d.m.Y');
                    $date_end = $data['date_end'] ?? date('d.m.Y');
                    $query->whereRaw("STR_TO_DATE(JSON_EXTRACT(props, '$.date.start'),'%d.%m.%Y') BETWEEN STR_TO_DATE(?, '%d.%m.%Y') AND STR_TO_DATE(?, '%d.%m.%Y')", [$date_start, $date_end]);
                    break;
                case 'time_start':
                    $query->whereHas('times', function ($q) use ($value) {
                        $q->whereRaw("start >= TIME(?)", [$value]);
                    });
                    break;
                case 'time_end':
                    $query->whereHas('times', function ($q) use ($value) {
                        $q->whereRaw("start <= TIME(?)", [$value]);
                    });
                    break;
                case 'user_id':
                    $query->whereUserId($data['user_id']);
                    break;
                case 'locality':
                    $query->where('props->city->city', 'LIKE', "%$value%");
                    break;
                case 'status':
                    if ($value !== 'all') $query->where('status', $value);
                    break;
                case 'sort':
                    switch ($value) {
                        case 'new':
                            $query->orderByDesc('id');
                            break;
                            case 'price':
                            $query->orderByDesc('price');
                            break;
                        case 'rating':
                            $query->select('excursions.*')
                                ->leftJoin('comments', function ($join) {
                                    $join->on('excursions.id', '=', 'comments.rel_id');
                                    $join->where('comments.rel_type', '=', Excursion::class);
                                })
                                ->addSelect(DB::raw('AVG(comments.like) as average_rating'))
                                ->groupBy('excursions.id')
                                ->orderBy('average_rating', 'desc')
                                ->orderBy('id', 'desc');

                            break;
                        case 'nearest':
                            break;
                        default:
                            $query->orderByDesc('id');
                    }
                    break;
                case 'hide_blocked': // Скрыть экскурсии заблокированных пользователей и не только
                    if (boolval($value) === true) {
                        $query->filterDisplay();
                    }
                    break;
            }
        }
        return $query;
    }
}
