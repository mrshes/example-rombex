<?php


namespace App\Services\TochkaBank;


use App\Helpers\Crypto\CryptoHelper;
use GuzzleHttp\Client;
use CPSignedData;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class TochkaBankV2
{
    protected $URL;
    protected $PIN;
    protected $SIGN_SYSTEM;
    protected $SIGN_THUMBPRINT;

    public function __construct()
    {
        $this->URL = config('services.tochka_v2.url');
        $this->PIN = config('services.tochka_v2.pin');
        $this->SIGN_SYSTEM = config('services.tochka_v2.sign_system');
        $this->SIGN_THUMBPRINT = config('services.tochka_v2.sign_thumbprint');
    }

    /**
     * Созжание запроса на АПИ
     * @param $method
     * @param array $data
     * @param string $request_method
     * @param $url
     * @return array|mixed
     */
    private function createRequest($method, array $data, string $request_method = 'POST', $url = null)
    {
        $id = Hash::make(uniqid());
        $data = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $data,
        ];
        $data_json = json_encode($data);
        // идентификатор площадки
        $sign_system = $this->SIGN_SYSTEM;
        // отпечаток ключа, которым будет производиться подписание
        $sign_thumbprint = $this->SIGN_THUMBPRINT;
        $pin = $this->PIN;
        $path = storage_path('request_sha256');
        $pathSgn = $path . '.sgn';
        $hash_sha256_string = hash('sha256', $data_json);
        $url = ($url) ? $url : $this->URL;
        $putInFile = file_put_contents($path, $hash_sha256_string);
        $command = "cryptcp -sign {$path} {$pathSgn} -detached -nocert -nochain -thumbprint {$sign_thumbprint} -pin {$pin}";
        $ex = exec($command);
        if ($ex === '[ErrorCode: 0x00000000]') {
            $sign_data = file_get_contents($pathSgn);
            $sign_data = str_replace(["\r\n", "\n"], '', $sign_data);

            $client = new Client();
            $headers = [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'sign-data' => $sign_data,
                'sign-system' => $sign_system,
                'sign-thumbprint' => $sign_thumbprint,
            ];
            try {
                $response = $client->request($request_method, $url, [
                    'headers' => $headers,
                    'body' => $data_json
                ]);
                $response = json_decode((string)$response->getBody(), true);
                if (is_array($response)) {
                    unlink($path);
                    $response['success'] = (isset($response['error'])) ? false : true;
                }
                return $response;
            } catch (ClientException $e) {
                Log::error('API TOCHKABANK V2 ERROR:', $e->getTrace());
                return [
                    'success' => false,
                    'result' => [
                        'code' => 0,
                        'message' => $e->getMessage()
                    ],
                ];
            }
        }
    }

    /**
     * @param string $text
     * @return array
     */
    public function echo($text = 'Hello Word!!!')
    {
        return $this->createRequest('echo', ['text' => $text]);
    }

////////////////////////////////////////////Бенефициары////////////////////////////////////////////

    /**
     * Создание бенефициара - ЮЛ
     * @param $inn
     * @param $name
     * @return array
     */
    public function create_beneficiary_ul($inn, $name)
    {
        return $this->createRequest('create_beneficiary_ul', [
            'inn' => $inn,
            'beneficiary_data' => [
                'name' => $name
            ],
        ]);
    }

    /**
     * Создание бенефициара - ИП
     * @param $inn
     * @param $name
     * @param $birth_date 'format like ('Y-m-d')'
     * @param $passport_series
     * @param $passport_number
     * @return array
     */
    public function create_beneficiary_ip($inn, $name, $birth_date, $passport_series, $passport_number)
    {
        return $this->createRequest('create_beneficiary_ip', [
            'inn' => $inn,
            'beneficiary_data' => compact([
                'name',
                'birth_date',
                'passport_series',
                'passport_number',
            ]),
        ]);
    }


    /**
     * Создание бенефициара - ФЛ
     * @param $inn
     * @param $name
     * @param $birth_date
     * @param $passport_series
     * @param $passport_number
     * @return array
     */
    public function create_beneficiary_fl($inn, $name, $birth_date, $passport_series, $passport_number)
    {
        return $this->createRequest('create_beneficiary_fl', [
            'inn' => $inn,
            'beneficiary_data' => compact([
                'name',
                'birth_date',
                'passport_series',
                'passport_number',
            ]),
        ]);
    }


    /**
     * Обновление бенефициара - ЮЛ
     * @param $inn
     * @param $name
     * @return array
     */
    public function update_beneficiary_ul($inn, $name)
    {
        return $this->createRequest('update_beneficiary_ul', [
            'inn' => $inn,
            'beneficiary_data' => [
                'name' => $name
            ],
        ]);
    }

    /**
     * Обновление бенефициара - ИП
     * @param $inn
     * @param $name
     * @param $birth_date
     * @param $passport_series
     * @param $passport_number
     * @return array
     */
    public function update_beneficiary_ip($inn, $name, $birth_date, $passport_series, $passport_number)
    {
        return $this->createRequest('update_beneficiary_ip', [
            'inn' => $inn,
            'beneficiary_data' => compact([
                'name',
                'birth_date',
                'passport_series',
                'passport_number',
            ]),
        ]);
    }


    /**
     * Обновление бенефициара - ФЛ
     * @param $inn
     * @param $name
     * @param $birth_date
     * @param $passport_series
     * @param $passport_number
     * @return array
     */
    public function update_beneficiary_fl($inn, $name, $birth_date, $passport_series, $passport_number)
    {
        return $this->createRequest('update_beneficiary_fl', [
            'inn' => $inn,
            'beneficiary_data' => compact([
                'name',
                'birth_date',
                'passport_series',
                'passport_number',
            ]),
        ]);
    }

    /**
     * Список бенефициаров
     * @param bool $is_active
     * @param string $legal_type
     * @return array
     */
    public function list_beneficiary($is_active = true, $legal_type = '')
    {
        return $this->createRequest('list_beneficiary', [
            'filters' => compact([
                'is_active',
//                'legal_type',
            ]),
        ]);
    }

    /**
     * Информация по бенефициару
     * @param $inn
     * @return array
     */
    public function get_beneficiary($inn)
    {
        return $this->createRequest('get_beneficiary', [
            'inn' => $inn,
        ]);
    }


    /**
     * Деактивация бенефициара
     * @param $inn
     * @return array
     */
    public function deactivate_beneficiary($inn)
    {
        return $this->createRequest('deactivate_beneficiary', [
            'inn' => $inn,
        ]);
    }


    /**
     * Активация бенефициара
     * @param $inn
     * @return array
     */
    public function activate_beneficiary($inn)
    {
        return $this->createRequest('activate_beneficiary', [
            'inn' => $inn,
        ]);
    }



////////////////////////////////////////////Виртуальные счета////////////////////////////////////////////

    /**
     * Создание виртуального счёта
     * @param $inn
     * @return array
     */
    public function create_virtual_account($inn)
    {
        $data = compact(['inn']);

        $method = 'create_virtual_account';
        return $this->createRequest($method, $data);
    }


    /**
     * Список виртуальных счетов
     * @param array $filter
     * @return array
     */
    public function list_virtual_account(array $filter)
    {
        $method = 'list_virtual_account';
        $data = compact(['filter']);

        $validator = Validator::make($data, [
            'filter' => 'array',
            'filter.beneficiary' => 'array',
            'filter.beneficiary' => 'array',
            'filter.beneficiary.is_active' => 'boolean',
            'filter.beneficiary.inn' => 'numeric',
            'filter.beneficiary.legal_type' => 'in:F,I,J',
        ]);
        if ($validator->fails()) {
            return [
                'success' => false,
                "errors" => $validator->errors()->getMessages(),
            ];
        }
        return $this->createRequest($method, $data);
    }

    /**
     * Информация по виртуальному счёту
     * @param $virtual_account
     * @return array
     */
    public function get_virtual_account($virtual_account)
    {
        $method = 'get_virtual_account';
        $data = compact(['virtual_account']);

        $validator = Validator::make($data, [
            'virtual_account' => 'string',
        ]);
        if ($validator->fails()) {
            return [
                'success' => false,
                "errors" => $validator->errors()->getMessages(),
            ];
        }
        return $this->createRequest($method, $data);
    }

    /**
     * Вывод денег с виртуального счёта
     * @param $virtual_account
     * @param array $recipient
     * @return array
     */
    public function refund_virtual_account(string $virtual_account, array $recipient)
    {
        $method = 'refund_virtual_account';
        $data = compact(['virtual_account', 'recipient']);

        $validator = Validator::make($data, [
            'virtual_account' => 'string',
            'recipient' => 'array',
            'recipient.amount' => 'numeric',
            'recipient.account' => 'string',
            'recipient.bank_code' => 'string',
            'recipient.name' => 'string',
            'recipient.inn' => 'string',
            'recipient.kpp' => 'string',
            'recipient.document_number' => 'string',
        ]);
        if ($validator->fails()) {
            return [
                'success' => false,
                "errors" => $validator->errors()->getMessages(),
            ];
        }
        return $this->createRequest($method, $data);
    }


////////////////////////////////////////////Сделки////////////////////////////////////////////

    /**
     * Создание сделки
     * @param $amount
     * @param array $payers
     * @param array $recipients
     * @return array
     */
    public function create_deal($amount, array $payers, array $recipients)
    {
        $method = 'create_deal';
        $data = compact(['amount', 'payers', 'recipients']);

        $validator = Validator::make($data, [
            'amount' => 'required|numeric',
            'payers' => 'required|array',
            'payers.virtual_account' => 'required|string',
            'payers.amount' => 'required|numeric',
            'recipients' => 'array',
        ]);
        if ($validator->fails()) {
            return [
                'success' => false,
                "errors" => $validator->errors()->getMessages(),
            ];
        }
        return $this->createRequest($method, $data);
    }

    /**
     * Обновление сделки
     * @param string $deal_id
     * @param array $deal_data
     * @return array
     */
    public function update_deal(string $deal_id, array $deal_data)
    {
        $method = 'update_deal';
        $data = compact(['deal_id', 'deal_data']);

        $validator = Validator::make($data, [
            'deal_id' => 'string',
            'deal_data' => 'required|array',
            'deal_data.amount' => 'string',

            'deal_data.payers' => 'array',
            'deal_data.payers.*.virtual_account' => 'string',
            'deal_data.payers.*.amount' => 'numeric',

            'deal_data.recipients' => 'array',
            'deal_data.recipients.*.type' => 'string|in:payment_contract,payment_contract_vir_acc,commission,ndfl',
            'deal_data.recipients.*.amount' => 'numeric',
            'deal_data.recipients.*.virtual_account' => 'string',

        ]);
        if ($validator->fails()) {
            return [
                'success' => false,
                "errors" => $validator->errors()->getMessages(),
            ];
        }
        return $this->createRequest($method, $data);
    }

    /**
     * Список сделок
     * @param array $filters
     * @return array
     */
    public function list_deal(array $filters = [])
    {
        $method = 'list_deal';
        $data = compact(['filters']);

        $validator = Validator::make($data, [
            'filters' => 'array',
            'filters.status' => 'in:new,in_process,closed,rejected',
            'filters.created_date_from' => 'date_format:Y-m-d',
            'filters.created_date_to' => 'date_format:Y-m-d',
        ]);
        if ($validator->fails()) {
            return [
                'success' => false,
                "errors" => $validator->errors()->getMessages(),
            ];
        }
        return $this->createRequest($method, $data);
    }

    /**
     * Информация по сделке
     * @param string $deal_id
     * @return array
     */
    public function get_deal(string $deal_id)
    {
        $method = 'get_deal';
        $data = compact(['deal_id']);

        $validator = Validator::make($data, [
            'deal_id' => 'string',
        ]);
        if ($validator->fails()) {
            return [
                'success' => false,
                "errors" => $validator->errors()->getMessages(),
            ];
        }
        return $this->createRequest($method, $data);
    }

    /**
     * Исполнение сделки
     * @param string $deal_id
     * @return array
     * @response {
     * "jsonrpc": "2.0",
     * "result": {
     * "deal_id": "cd9f39ab-ffb8-41b1-a445-f2e9eb158943"
     * }
     * }
     */
    public function execute_deal(string $deal_id)
    {
        $method = 'execute_deal';
        $data = compact(['deal_id']);

        $validator = Validator::make($data, [
            'deal_id' => 'string',
        ]);
        if ($validator->fails()) {
            return [
                'success' => false,
                "errors" => $validator->errors()->getMessages(),
            ];
        }
        return $this->createRequest($method, $data);
    }

    /**
     * Отмена сделки
     * @param string $deal_id
     * @return array
     * @response {
     * "jsonrpc": "2.0",
     * "result": {
     * "deal_id": "cd9f39ab-ffb8-41b1-a445-f2e9eb158943"
     * }
     * }
     */
    public function rejected_deal(string $deal_id)
    {
        $method = 'rejected_deal';
        $data = compact(['deal_id']);

        $validator = Validator::make($data, [
            'deal_id' => 'string',
        ]);
        if ($validator->fails()) {
            return [
                'success' => false,
                "errors" => $validator->errors()->getMessages(),
            ];
        }
        return $this->createRequest($method, $data);
    }



    //////////////////////////////Список платежей///////////////////////////

    /**
     * Список платежей
     * @param array $filters
     * @return array|mixed
     */
    public function list_payments(array $filters = [])
    {
        $method = 'list_payments';
        $data = compact(['filters']);

//        $validator = Validator::make($data, [
//            'filters' => 'array',
//        ]);
//        if ($validator->fails()) {
//            return [
//                'success' => false,
//                "errors" => $validator->errors()->getMessages(),
//            ];
//        }
        return $this->createRequest($method, $data);
    }

    /**
     * Информация по платежу
     * @param string $payment_id
     * @return array|mixed
     */
    public function get_payment(string $payment_id)
    {
        $method = 'get_payment';
        $data = compact(['payment_id']);

        $validator = Validator::make($data, [
            'filters' => 'array',
        ]);
        if ($validator->fails()) {
            return [
                'success' => false,
                "errors" => $validator->errors()->getMessages(),
            ];
        }
        return $this->createRequest($method, $data);
    }

    /**
     * Идентификация платежей
     * @param string $payment_id
     * @param array $owners
     * @return array|mixed
     */
    public function identification_payment(string $payment_id, array $owners = [])
    {
        $method = 'identification_payment';
        $data = compact(['payment_id', 'owners']);

        $validator = Validator::make($data, [
            'filters' => 'array',
        ]);
        if ($validator->fails()) {
            return [
                'success' => false,
                "errors" => $validator->errors()->getMessages(),
            ];
        }
        return $this->createRequest($method, $data);
    }

    /**
     * Пополнение счёта
     * @param $amount
     * @param string $recipient_account
     * @param string $recipient_bank_code
     * @param string $payer_account
     * @param string $payer_bank_code
     * @param string $purpose
     * @return array|mixed
     */
    public function transfer_money($amount, string $recipient_account, string $recipient_bank_code, string $payer_account, string $payer_bank_code, string $purpose = '')
    {
        $method = 'transfer_money';
        $data = compact([
            'amount',
            'recipient_account',
            'recipient_bank_code',
            'payer_account',
            'payer_bank_code',
            'purpose',
        ]);

//        $validator = Validator::make($data, [
//            'filters' => 'array',
//        ]);
//        if ($validator->fails()) {
//            return [
//                'success' => false,
//                "errors" => $validator->errors()->getMessages(),
//            ];
//        }
        return $this->createRequest($method, $data, 'POST', 'https://stage.tochka.com/api/v1/tender-helpers/jsonrpc');
    }


}
