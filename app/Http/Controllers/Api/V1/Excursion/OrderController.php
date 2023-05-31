<?php

namespace App\Http\Controllers\Api\V1\Excursion;

use App\Http\Requests\Http\Controllers\Api\V1\Excursion\OrderController\ComplaintRequest;
use App\Http\Requests\V1\Excursion\OrderController\StoreRequest;
use App\Models\Complaint;
use App\Models\ExcursionTimePoint;
use App\Models\OrderRefund;
use App\Models\Revoke;
use App\Services\BaseRepository;
use App\Services\Order\OrderCalculatingAmount;
use App\Services\Order\OrderRepository;
use App\Services\Order\OrderService;
use App\Services\User\UserRepository;
use App\Services\User\UserService;
use Illuminate\Support\Facades\Auth;
use Modules\Admin\Models\ARole;
use App\Models\Excursion;
use App\Models\Order;
use App\Models\QrCode;
use App\Models\User;
use App\Notifications\UserOrderNotification;
use Modules\Payment\Services\Payment\Interfaces\Payment;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use phpDocumentor\Reflection\Types\Mixed_;

/**
 * @group Бронирование
 */
class OrderController extends Controller
{
    /**
     * @var Order
     */
    protected $model;

    public function __construct(Order $model)
    {
        $this->model = $model;
    }

    /**
     * Показать список бронирования
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $data = [
            'user_id' => $user->id,
        ];
        $query = $this->model->fetch($data)->orderByDesc('id')->with(['excursion.images', 'excursion.times', 'excursion.user.organization']);
        return $query->paginate(10);
    }

    /**
     * Бронирование экскурсии
     * @queryParam number_children integer
     * @queryParam number_people integer
     * @queryParam description string
     * @queryParam excursion_id integer
     *
     * @param Request $request
     * @param Payment $payment
     * @param QrCode $qrCode
     * @return bool
     */
    public function store(StoreRequest $request, Payment $payment, QrCode $qrCode, OrderService $orderService)
    {
        return DB::transaction(function () use ($request, $payment, $qrCode, $orderService) {
            $data = $request->validated();
            $authUser = $request->user('api');
            // Находим пользователя для которого покупается экскурсия
            /** @var User $user */
            $user = (new UserService())->findOrCreateByEmail($data['email'], $data['phone'], $data['name'], $data['surname'], $data['patronymic'] ?? null);

            $data['user_id'] = $user->id;
            // Указываем авторизованного пользователя, кто совершил покупку
            $data['order_user_id'] = $authUser ? $authUser->id : null;
            $data['currency'] = 'RUB';
            $point = ExcursionTimePoint::findOrFail($data['point_id']);

            $orderCalculatingAmount = new OrderCalculatingAmount($data);
            $orderCalculatingAmount->setPoint($point);
            $amount = $orderCalculatingAmount->calculating();

            if ($data['total_amount'] != $amount) return responseHelper()->error('Ошибка в калькуляции суммы заказа.');

            $data['amount'] = $amount;

//             Не проверяем, при вызове из тестов
            if (!app()->runningUnitTests()) {
                // Проверка можно ли купить экскурсию
                $canBuying = $orderService->canBuying($data['excursion_id'], $data['date_start'], $data['time_start']);
                if (!$canBuying['status']) {
                    return responseHelper()->error($canBuying['message'], 403);
                }
                // Проверка на повторную покупку
                if (!$request->get('ignore_repeat_order', false)) {
                    $checkIssetEntry = $orderService->checkOrderBefore($data);
                    if ($checkIssetEntry) {
                        return responseHelper()->error('Order was buying before.', 403, [
                            'description' => 'To ignore the check, send the field "ignore_repeat_order" with the value "true".'
                        ]);
                    }
                }
            }

            $items = [
                'point' => $point->toArray(),
                'languages' => $data['languages'] ?? null,
                'transfer' => $data['transfer'] ?? null,
            ];
            $order = $orderService->store($data, $items);
            $qrCode->make($order);
            $payment = $payment->createPaymentForOrder($order)->toArray();
            return response()->json($payment);
        });
    }

    /**
     * Отобразить запись по id
     * @queryParam id required
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        return response()->json($this->model->findOrFail($id));
    }

    /**
     * Обновить запись.
     * @queryParam number_children integer
     * @queryParam number_people integer
     * @queryParam description string
     * @queryParam excursion_id integer
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $data = $request->validate([
            'number_children' => 'sometimes|integer',
            'number_people' => 'sometimes|integer',
            'description' => 'sometimes|string',
            'excursion_id' => 'sometimes|integer',
        ]);
        $record = $this->model->find($id)->fill($data);
        $record->save();

        return response()->json($record);
    }

    /**
     *  Удалить запись по id
     * @queryParam id required
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $destroy = $this->model->findOrFail($id)->delete();
        return response()->json($destroy);
    }

    /**
     * Проверка достаточно ли денег для брони
     * @param $data
     * @param $user
     * @return bool
     */
    protected function getPayData($data, $user)
    {
        $response = ['status' => false, 'message' => 'Недостаточно денег на счету', 'sum' => 0];
        $recordId = $data['excursion_id'];
        $balance = $user->balance;
        if ($balance) {
            $record = Excursion::findOrFail($recordId);
            $number_people = ($data['number_people'] <= 0) ? 1 : $data['number_people']; // Количество взрослых
            $sum = $record->amount * $number_people; // Цена умноженная на количество взрослых
            if ($balance->balance >= $sum) {
                $response = ['status' => true, 'message' => '', 'sum' => $sum];
            }
        }
        return $response;
    }


    /**
     * Купленные экскурсии у пользователя
     * @param Request $request
     * @return mixed
     */
    public function userSales(Request $request)
    {
        $user = $request->user();
        $orders = $this->model->userSales($user)->with(['excursion.images', 'excursion', 'excursion.user.organization'])->orderByDesc('id')->get();
        return $orders;
    }

    /**
     * Печать билета
     * @param $id
     * @return mixed
     */
    public function downloadTicket($id)
    {
        $order = Order::with('qr_code')->findOrFail($id);
        return $order->getPDFTicket()->stream('ticket.pdf');
    }

    /**
     * Отмена заказа
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function refund(Request $request, Payment $payment): \Illuminate\Http\JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $data = $request->validate([
                'order_id' => 'numeric|required',
                'description' => 'string|sometimes',
            ]);
            /** @var Order $order */
            $order = $this->model->findOrFail($data['order_id']);
            $order->getService()->refundHandler($request->user(), $data['description'] ?? '');

            return responseHelper()->success('OK');
        });
    }

    /**
     * Можно ли делать отмену заказа
     * @param Request $request
     * @return array
     */
    public function canRefund(Request $request): array
    {
        $data = $request->validate([
            'order_id' => 'required|numeric'
        ]);
        /** @var Order $order */
        $order = $this->model->findOrFail($data['order_id']);
        return $order->getService()->canRefund();
    }

    /**
     * Создание жалобы на экскурсию
     * @param ComplaintRequest $request
     * @return Complaint|\Illuminate\Http\JsonResponse
     */
    public function complaint(ComplaintRequest $request)
    {
        $data = $request->validated();

        /** @var Order $order */
        $order = Order::findOrFail($data['order_id']);
        $user = $request->user();
        try {
            return $order->getRepository()->makeComplaint($user, $data);
        } catch (\Exception $e) {
            return responseHelper()->error($e->getMessage(), $e->getCode());
        }
    }

    /**
     * Проверка можно ли создавать жалобу
     * @param Request $request
     */
    public function canComplaint(Request $request)
    {
        $data = $request->validate([
            'order_id' => 'required|numeric'
        ]);
        /** @var Order $order */
        $order = $this->model->findOrFail($data['order_id']);
        $user = $request->user();
        $check = $order->getRepository()->canComplaint($user);
        return response($check, $check['status'] ? 200 : 403);
    }

}
