<?php


namespace App\Services\Order;


use App\Models\AppConfig;
use App\Models\Excursion;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\User;
use App\Services\BaseService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\MONETA\Services\MonetaService;
use Modules\MONETA\Services\Payanyway\PayanywayService;
use Modules\Payment\Models\BillAction;
use Modules\Payment\Services\Payment\Interfaces\Payment;
use Modules\Payment\Services\Transaction\Transaction;

class OrderService extends BaseService
{
    /**
     * @return Order
     */
    protected function getModel(): Order
    {
        return parent::getModel() ?: new Order();
    }

    /**
     * Сохранение заказа
     * @param array $data
     * @return $this
     */
    public function store(array $data, array $items = []): Order
    {
        $entry = $this->getModel();
        $entry->fill($data);
        $entry->items = $items;
        $entry->setStatus(0);
        $entry->save();
        return $entry;
    }


    /**
     * Можно ли делать возврат
     * @return array
     */
    public function canRefund(): array
    {
        /** @var Order $order */
        $order = $this->getModel();
        $excursionNonReturnDays = $order->excursion->getService()->getDaysBookingByConfigKey('time_min_booking');
        $percentage_penalty = 100 - AppConfig::getByKey('percentage_penalty');
        $dateStartWithExpired = Carbon::parse($order->date_start)->subDays(1);

        $currDate = Carbon::now();
        return [
            'status' => !$this->isStatus(2) && ($this->isStatusTransaction(2) || $this->isStatusTransaction(3)),
            'no_penalty_date' => $dateStartWithExpired->format('d.m.Y'),
            'percent'=> ($currDate->lte($dateStartWithExpired)) ? 100 : $percentage_penalty,
        ];
    }

    /**
     * Возврат средств
     * @param $description
     * @return OrderRefund
     */
    public function refundHandler(User $user, string $description): ?OrderRefund
    {
        $canRefund = $this->canRefund();
        if (!$canRefund['status']) abort(403);
        /** @var Order $order */
        $order = $this->getModel();
        /** @var Payment $paymentService */
        $paymentService = app(Payment::class);
        /** @var BillAction $transaction */
        $billAction = $order->transaction;
        $fundsHolding = $billAction->getService()->isHolding();
        $refundOrder = (new OrderRefund())->store($order, $user, $description ?? '');

        $billAction->getService()->setStatus(5)->save();
        $order->setStatus(2);
        if ($fundsHolding) {
            $paymentService->holdingCanceling($billAction);
        } else {
            $this->makeRefund($canRefund['percent']);
        }
        return $refundOrder;
    }

    /**
     * Запросить возврат средств
     * @param int $percent
     * @param string $description
     * @return bool
     * @throws \Exception
     */
    public function makeRefund(int $percent, string $description = ''): bool
    {
        return DB::transaction(function () use ($percent, $description) {
            $order = $this->getModel();
            $transaction = $order->transaction;
            $percentAmount = $order->amount * $percent / 100;
            /** @var PayanywayService $paymentService */
            $paymentService = app(Payment::class);
            $paymentService->refund($transaction, $percentAmount, $description);
            return true;
        });
    }

    /**
     * вычислить дату окончания
     * @return Carbon
     */
    public function calculateDateFinish(string $dateStart, string $timeStart): Carbon
    {
        $time = Carbon::parse($timeStart);
        $expiredTime = (new AppConfig())->getExpiredTime();
        $date = Carbon::parse($dateStart);
        return $date->setTimeFrom($time)->addDays($expiredTime);
    }

    /**
     * Сверяет статус
     * @param int $statusID
     * @return bool
     */
    public function isStatus(int $statusID): bool
    {
        $status = $this->getModel()->getStatuses()[$statusID];
        return $this->getModel()->status === $status;
    }

    /**
     * Проверить статус транзакции заказа
     * @param int $id
     * @return mixed
     */
    public function isStatusTransaction(int $id)
    {
        return $this->getModel()->transaction->isStatus($id);
    }

    /**
     * @return Carbon
     */
    public function getDateBooking(): Carbon
    {
        $entry = $this->getModel();
        return Carbon::parse($entry->items['date_start'] . ' ' . $entry->items['time_start']);
    }

    /**
     * Возможно ли купить экскурсию
     * @param int $excursion_id
     * @param string $date_start
     * @param string $time_start
     * @return array
     */
    public function canBuying(int $excursion_id, string $date_start, string $time_start): array
    {
        $response = [
            'status' => true,
            'message' => '',
        ];
        $dateNow = Carbon::now();
        /** @var Excursion $excursion */
        $excursion = Excursion::findOrFail($excursion_id);
        $dateBooking = Carbon::parse($date_start . ' ' . $time_start);
        $excMaxBookingDay = 7;
        // Минимальная дата бронирования экскурсии(из конфига)
        $excMinBookingDate = clone $dateBooking;
        $excMinBookingDate->subDays($excMaxBookingDay);
        // Минимальная дата для брони, которую указал партнер
        $datePossibilityBooking = $this->getDatePossibilityBooking($excursion, $dateBooking);
        if ($dateNow->gte($datePossibilityBooking)) { // Больше равно
            $response['status'] = false;
            $response['message'] = __('order_expired_time_booking');
        } elseif ($dateNow->lte($excMinBookingDate)) { //Меньше равно
            $response['status'] = false;
            $response['message'] = __('order_max_time_booking');
        }
        return $response;
    }

    /**
     * Получить дату до которой можно купить(настраивается при создании экскурсии)
     * @param Excursion $excursion
     * @param Carbon $dateBooking
     * @return Carbon
     */
    public function getDatePossibilityBooking(Excursion $excursion, Carbon $dateBooking): Carbon
    {
        $date = clone $dateBooking;
        $excItems = $excursion->props;
        $bookingBeforeDay = Arr::get($excItems, 'booking_before.day');
        $bookingBeforeHour = Arr::get($excItems, 'booking_before.hour');
        if ($bookingBeforeDay) $date = $date->subDays($bookingBeforeDay);
        if ($bookingBeforeHour) $date = $date->subHours($bookingBeforeHour);
        return $date;
    }

    /**
     * Минимальное время бронирования
     * @return bool
     */
    public function isComeMinBookingTime(): bool
    {
        $entry = $this->getModel();
        $days = $entry->excursion->getService()->getDaysBookingByConfigKey('time_min_booking');
        $dateMin = $this->getDateBooking()->subDays($days);
        $dateNow = Carbon::now();
        return $dateNow->gte($dateMin);
    }

    /**
     * @return bool
     */
    public function isHolding(): bool
    {
        return !$this->isComeMinBookingTime();
    }

    /**
     * Проверить наличие жалоб на заказ
     * @return bool
     */
    public function hasComplaints(): bool
    {
        return $this->getModel()->complaints->count() > 0;
    }

    /**
     * Получить последнюю транзакцию для оплаты
     * @return BillAction
     */
    public function getTransactionPayment(): ?BillAction
    {
        return $this->getModel()->transaction;
    }

    /**
     * Пробиваем qrCode билета
     * @return $this
     */
    public function confirm(?Carbon $date = null): self
    {
        $order = $this->getModel();
        $order->date_confirm = $date ?: Carbon::now();
        $order->save();
        return $this;
    }

    /**
     * Списание холдированных средств
     * @return mixed
     */
    public function performHolding()
    {
        return DB::transaction(function () {
            /** @var PayanywayService $paymentService */
            $paymentService = app(Payment::class);
            $transaction = $this->getTransactionPayment();
            return $paymentService->holdingConfirm($transaction);
        });
    }

    /**
     * Пробиваем qrCode билета
     * @return $this
     */
    public function confirmAndHolding(): self
    {
        return DB::transaction(function () {
            // Находим последнюю валидную транзакцию
            /** @var BillAction $transaction */
            // Зачисляем средства на счет
            $transaction->getService()->finish();

            // устанавливаем статус для заказа
            $this->getModel()->setStatus(1); // COMPLETED
            $this->confirm();
            $responseHC = $this->performHolding();
            if (!$responseHC['status']) throw new \Exception('Confirmation of the holding was unsuccessful.');
            return $this;
        });
    }

    /**
     * Проверка, принадлежит ли заказ пользователю или его рабочей группе
     * @param User $user
     * @return bool
     */
    public function userHasAccess(User $user): bool
    {
        $order = $this->getModel();
        $excursionUserId = $order->excursion->user_id;
        $employee = $user->employees()->whereId($excursionUserId)->first();
        return ($user->id === $excursionUserId || !is_null($employee));
    }

    /**
     * Метод для проверки повторной покупки экскурсии
     * @param array $data
     */
    public function checkOrderBefore(array $data)
    {
        return $this->getModel()->where([
            ['user_id', $data['user_id']],
            ['excursion_id', $data['excursion_id']],
            ['point_id', $data['point_id']],
            ['date_start', $data['date_start']],
            ['time_start', $data['time_start']],
            ['number_adult', $data['number_adult']],
        ])
            ->where('status', '!=', $this->getModel()->getStatuses()[1])
            ->first();
    }


}
