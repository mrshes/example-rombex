<?php

namespace App\Models;

use App\Notifications\TicketNotification;
use App\Services\Order\OrderRepository;
use App\Services\Order\OrderService;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Payment\Models\BillAction;
use Modules\Payment\Services\Payment\Interfaces\Payment;
use Barryvdh\DomPDF\PDF;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use phpDocumentor\Reflection\Types\This;

class Order extends Model
{
    use SoftDeletes;


    protected $statuses = [
        0 => 'NOT_COMPLETED',
        1 => 'COMPLETED',
        2 => 'CANCELED',
        3 => 'SUSPENDED',
    ];


    /******************************************RELATIONS******************************************/

    public function qr_code(): \Illuminate\Database\Eloquent\Relations\MorphOne
    {
        return $this->morphOne(QrCode::class, 'qr_codeable');
    }


    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function excursion(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Excursion::class, 'excursion_id');
    }


    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Сотрудники
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function employee(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * жалобы
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function complaints(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Complaint::class, 'complaintable', 'rel_type', 'rel_id');
    }

    /**
     * Получить запись об оплате
     * @return \Illuminate\Database\Eloquent\Relations\MorphOne
     */
    public function transaction(): \Illuminate\Database\Eloquent\Relations\MorphOne
    {
        return $this->morphOne(BillAction::class, 'actionable', 'rel_type', 'rel_id');
    }

    /******************************************SCOPED******************************************/

    /**
     * Выборка заказов принадлежащих пользователю
     * @param $query
     * @param User $user
     * @return mixed
     */
    public function scopeUserSales($query, User $user)
    {
        return $query->whereHas('excursion', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        });
    }

    /**
     * Выборка активных записей
     * @param $query
     * @param User $user
     * @return mixed
     */
    public function scopeWhereIsActive($query)
    {
        return $query->whereNotExpiredTime()->whereNotConfirmed();
    }

    /**
     * Поиск записей
     * @param $query
     * @param $data
     * @return mixed
     */
    public function scopeFetch($query, $data)
    {
        foreach ($data as $key => $value) {
            switch ($key) {
                case 'user_id':
                    $query->whereUserId($value);
                    break;
                case 'employee_id':
                    $query->whereEmployeeId($value);
                    break;
                case 'search':
                    if (is_numeric($value)) {
                        $query->whereId($value);
                    } else {
                        $query->whereHas('excursion', function ($q) use ($value) {
                            $q->where('name', 'LIKE', "%{$value}%");
                        });
                    }
                    break;
                case 'date':
                    $query->where('date_start', $value);
                    break;
            }
        }
        return $query;
    }

    /**
     * Заказы которые закончились
     * @param $query
     * @return mixed
     */
    public function scopeWhereExpiredTime(Builder $query)
    {
        $days = (new AppConfig())->getExpiredTime();
        return $this->whereSubDateStart($days);
    }

    /**
     * Заказы которые еще не начались
     * @param $query
     * @return mixed
     */
    public function scopeWhereNotExpiredTime($query)
    {
        $days = (new AppConfig())->getExpiredTime();
        return $this->whereSubDateStart($days, false, '>=');
    }

    /**
     * Получить заказы, которые закончились с учетом продолжительности экскурсии + время на ожидание с конфига
     * @param Builder $builder
     * @return Builder
     */
    public function scopeWhereOver(Builder $builder): Builder
    {
        $days = (new AppConfig())->getExpiredTime();

        return $builder
            ->join('excursions', 'orders.excursion_id', '=', 'excursions.id')
            ->whereRaw("ADDTIME(ADDDATE(CONCAT(date_start, ' ', time_start), {$days}), JSON_UNQUOTE(JSON_EXTRACT(excursions.props, '$.duration_time'))) <= NOW()")
            ->selectRaw('orders.*');
    }


    /**
     * Записи которые пробиты
     * @param Builder $query
     * @return Builder
     */
    public function scopeWhereConfirmed(Builder $query): Builder
    {
        return $query->whereNotNull('date_confirm')->whereNotNull('employee_id')->whereRaw('date_confirm >= NOW()');
    }

    /**
     * Записи у которых билет не пробит
     * @param Builder $query
     * @return Builder
     */
    public function scopeWhereNotConfirmed(Builder $query): Builder
    {
        return $query->whereNull('date_confirm')->whereNull('employee_id');
    }


    /**
     * Записи у которых прошла оплата
     * @param Builder $query
     * @return Builder
     */
    public function scopeWherePaymentConfirmed(Builder $query): Builder
    {
        return $query->whereHas('transaction', function ($q) {
            $q->whereConfirmed();
        });
    }

    /**
     * Выборка записей со статусом "ожидается списание денег"
     * @param Builder $query
     * @return Builder
     */
    public function scopeWhereCanBeCapture(Builder $query): Builder
    {
        return $query->whereHas('transaction', function ($q) {
            $q->whereCanBeCapture();
        });
    }



    /**
     * Получение заказов отталкиваясь от дня бронирования
     * @param Builder $query
     * @param int $days
     * @param bool $sub
     * @return Builder
     */
    public function scopeWhereSubDateStart(Builder $query, int $days, bool $sub = false, string $sign = '<='): Builder
    {
        $method = $sub ? 'SUBDATE' : 'ADDDATE';
        return $query->whereRaw("{$method}(CONCAT(date_start, ' ', time_start), {$days}) {$sign} NOW()");
    }


    /**
     * Выборка до даты покупки
     * @param Builder $query
     * @param int $days
     * @return Builder|\Illuminate\Database\Query\Builder
     */
    public function scopeWhereSubDateOrder(Builder $query, $days = 0)
    {
        return $query->fromRaw('excursions, orders')->whereRaw("SUBDATE(CONCAT(JSON_UNQUOTE(JSON_EXTRACT(items, '$.date_start')), ' ', JSON_UNQUOTE(JSON_EXTRACT(items, '$.time_start'))), ?) <= NOW()", [$days]);
    }


    /**
     * Поиск заказа по qrCode
     * @param Builder $query
     * @param $qrCode
     * @return Builder
     */
    public function scopeWhereQrCode(Builder $query, $qrCode): Builder
    {
        return $query->whereHas('qr_code', function ($q) use ($qrCode) {
            $q->check($qrCode);
        });
    }

    /**
     * Получить заказы у которых нет жалоб
     * @param Builder $builder
     * @return mixed
     */
    public function scopeWhereDoesntHaveComplaints(Builder $builder)
    {
        return $this->doesntHave('complaints');
    }

    /**
     * Получить заказы, которые удовлетворяют ограничениям в днях для каждой записи
     * @param Builder $query
     * @return Builder
     */
    public function scopeWhereMinDayOrders(Builder $query): Builder
    {
        $type = (new Excursion())->getTypes();
        $subtype = (new Excursion())->getSubtypes();
        $days = AppConfig::getByKey('time_min_booking');
        return $query->where(function ($query) use ($subtype, $days, $type) {
            $query->whereSubDateOrder($days['vip']);
            $query->whereHas('excursion', function (Builder $excursion) use ($subtype) {
                $excursion->where('type', $subtype[2]);
            });
        })
            ->orWhere(function (Builder $query) use ($type, $subtype, $days) {
                $query->whereSubDateOrder($days['individual']);
                $query->whereHas('excursion', function (Builder $excursion) use ($type, $subtype) {
                    $excursion->where('subtype', $type[0]);
                    $excursion->where('type', '!=', $subtype[2]);
                });
            })
            ->orWhere(function (Builder $query) use ($type, $subtype, $days) {
                $query->whereSubDateOrder($days['group']);
                $query->whereHas('excursion', function (Builder $excursion) use ($type, $subtype) {
                    $excursion->where('subtype', $type[1]);
                    $excursion->where('type', '!=', $subtype[2]);
                });
            });
    }
    /**********************************************MUTATIONS********************************************/

    /**
     *
     * @param $value
     * @return string
     */
    public function getCanRefundAttribute(): bool
    {
        $currDate = \Illuminate\Support\Carbon::now();
        $recordDate = Carbon::parse($this->date_start)->subDay();
        return $recordDate->gte($currDate);
    }

    /**
     * @param $value
     * @return string
     */
    public function getTimeAttribute($value)
    {
        return Carbon::parse($value)->format('H:i');
    }

    /**
     * @param $value
     * @return mixed
     */
    public function getStatusPayAttribute($value)
    {
        return $this->transaction->status_ru ?? null;
    }

    /**
     * @param $value
     * @return array|string|null
     */
    public function getStatusRuAttribute($value)
    {
        $key = 'statuses.order.statuses.' . $this->status;
        return __($key);
    }

    /**
     * @return string[]
     */
    public function getStatuses(): array
    {
        return $this->statuses;
    }
    /******************************************METHODS******************************************************/


    /**
     * @param $id
     * @return $this
     */
    public function setStatus($id): Order
    {
        $this->status = $this->statuses[$id];
        if ($this->exists) {
            $this->save();
        }
        return $this;
    }


    /**
     * Проверка пробит ли билет
     * @return bool
     */
    public function isConfirm(): bool
    {
        return !is_null($this->date_confirm) && !is_null($this->employee_id);
    }


    /**
     * Получить билет заказа
     * @return PDF
     */
    public function getPDFTicket(): PDF
    {
        /** @var PDF $pdf */
        $pdf = app('dompdf.wrapper');
        $viewData = $this->getTicketViewData();
        return $pdf->loadView($viewData['view'], $viewData['data'])->setPaper('a4', 'landscape');
    }

    /**
     * Абстракция на получение данных для PDF и Html билетов
     * @return array
     */
    public function getTicketViewData(): array
    {
        return [
            'view' => 'pdf.ticket',
            'data' => [
                'order' => $this->load(['qr_code', 'excursion', 'excursion.user.organization'])->toArray(),
                'app_config' => AppConfig::all(),
            ]
        ];
    }

    /**
     * Отправить билет покупателю
     */
    public function sendUserTicket()
    {
        $userWhoBuy = $this->user;
        $userWhoBuy->notify(new TicketNotification($this));
    }

    /**
     * Списание денег замороженных Холдированием
     * @param null $amount
     * @return mixed
     */
    public function capturePayment($amount = null)
    {
        $pay = $this->transaction;
        $payment = app(Payment::class);
        $capture = $payment->capture([
            'payment_id' => $pay->order_id,
            'amount' => $amount ? $amount : $pay->amount,
        ]);

    }


    /**
     * Сгенерировать уникальный id для заказ
     * @return string
     */
    public function generateId()
    {
        return md5("{$this->id}|{$this->created_at}");
    }

    /**
     * Проверить уникальный id для заказ
     * @param $hash
     * @return bool
     */
    public function checkId($hash)
    {
        return Hash::check("{$this->id}|{$this->created_at}", $hash);
    }

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @return OrderRepository
     */
    public function getRepository(): OrderRepository
    {
        return new OrderRepository($this);
    }

    /**
     * @return OrderService
     */
    public function getService(): OrderService
    {
        return new OrderService($this);
    }
}
