<?php


namespace App\Services\Order;


use App\Models\ExcursionTime;
use App\Models\Order;
use App\Models\User;
use App\Services\Transaction\Interfaces\Transaction;
use Illuminate\Support\Carbon;
use Modules\Payment\Models\BillAction;

class OrderRepository extends \App\Services\BaseRepository
{

    protected function getModelClass(): string
    {
        return Order::class;
    }

    /**
     * Получить пользователей кто сделал заказ экскурсии на определенное время
     * @param ExcursionTime $time
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getUsersWhoOrderByTime(ExcursionTime $time): \Illuminate\Database\Eloquent\Collection
    {
        return User::whereHas('orders', function ($q) use ($time) {
            $q->where('excursion_id', $time->excursion_id)
                ->where('items->point->excursion_time_id', $time->id)
                ->whereIsActive()
                ->select('id');
        })
            ->get();
    }

    /**
     * Получить заказы сделанные на определенное время
     * @param ExcursionTime $time
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getOrderByTime(ExcursionTime $time): \Illuminate\Database\Eloquent\Collection
    {
        return Order::with('user')->where('excursion_id', $time->excursion_id)
            ->where('items->point->excursion_time_id', $time->id)
            ->whereIsActive()
            ->get();
    }



    /**
     * Проверка закончилась ли экскурсия, дата меньше или равна
     * @return bool
     */
    public function isFinish(): bool
    {
        return Carbon::parse($this->getModel()->date_finish)->lte(Carbon::now());
    }

    /**
     * Создание жалобы на экскурсию
     * @param User $user
     * @param array $data
     * @return mixed
     */
    public function makeComplaint(User $user, array $data)
    {
        $order = $this->getModel();
        if (!$this->canComplaint($user)['status']) throw new \Exception(__('complaint_denied'), 403);
        $complaint = $order->complaints()->firstOrcreate([
            'user_id' => $user->id,
            'type' => isset($data['type']) ? strtoupper($data['type']) : null,
            'description' => $data['description']
        ]);
        $order->setStatus(3); // suspended
        return $complaint;
    }

    /**
     * Проверка можно ли создавать жалобу
     * @param User $user
     * @return array
     */
    public function canComplaint(User $user): array
    {
        $order = $this->getModel();
        $dateFinish = Carbon::parse($order->date_finish)->addDay();
        $dateNow = Carbon::now();
        $checkDate = $dateFinish->gte($dateNow);
        $complaints = $order->complaints;
        $complaintsExist = ($complaints->count() != 0);
        $status = (
            $order->user_id === $user->id
            && is_null($order->date_confirm)
            && $checkDate
            && !$complaintsExist);
        return [
            'checkUser' => $order->user_id === $user->id,
            'checkTicket' => is_null($order->date_confirm),
            'checkDate' => $checkDate,
            'expiredDate' => $dateFinish,
            'complaintExists' => $complaintsExist,
            'status' => $status
        ];
    }




}
