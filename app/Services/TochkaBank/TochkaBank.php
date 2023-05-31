<?php


namespace App\Services\TochkaBank;


use App\Models\AppConfig;
use GuzzleHttp\Client;

class TochkaBank
{
    protected $url;
    protected $client_id;
    protected $client_secret;
    protected $code;
    protected $test_mode;
    protected $path;
    protected $path_test;

    public function __construct()
    {
        $this->url = config('services.tochka.url');
        $this->client_id = config('services.tochka.client_id');
        $this->client_secret = config('services.tochka.client_secret');
        $this->code = config('services.tochka.code');
        $this->test_mode = config('services.tochka.test_mode');
        $this->path = config('services.tochka.path');
        $this->path_test = config('services.tochka.path_test');
    }

    /**
     * Основной метод для создания запросов
     * @param $method
     * @param $url
     * @param array $params
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function makeRequest($method, $url, $params = [])
    {
        $base_url = $this->url;
        $base_url .= ($this->test_mode) ? $this->path_test : $this->path;
        $base_url .= '/';

        $client = new Client([
            // Base URI is used with relative requests
            'base_uri' => $base_url,
        ]);
        $api = $client->request($method, $url, $params);
        $response = json_decode((string)$api->getBody(), true);
        return $response;
    }

    /**
     * Отправка запроса для получение access_token, подставляет client_id, client_secret
     * @param $method
     * @param $url
     * @param array $params
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function sendAuth($method, $url, $params = [])
    {
        $default = [
            'form_params' => [
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
            ],
        ];
        $params = array_merge_recursive($default, $params);
        $response = $this->makeRequest($method, $url, $params);
        return $response;
    }

    /**
     * Отправлка запросов, подстановка токена если он есть в БД
     * @param $method
     * @param $url
     * @param array $params
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function send($method, $url, $params = [])
    {
        $bearer = config('services.tochka.bearer');
        $default = [
            'headers' => [
                'Authorization' => 'Bearer ' . $bearer,
            ],
        ];

        $params = array_merge_recursive($default, $params);
        $response = $this->makeRequest($method, $url, $params);
        return $response;
    }

    /**
     * Получить access_token по коду
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function authorizationCode()
    {
        $res = $this->sendAuth('POST', 'oauth2/token', [
            'form_params' => [
                'grant_type' => 'authorization_code',
                'code' => $this->code,
            ]
        ]);
        return $res;
    }

    /**
     * Обновление токена
     * @param $refreshToken
     * @return mixed
     */
    public function refresh_token($refreshToken)
    {
        $res = $this->sendAuth('POST', 'oauth2/token', [
            'form_params' => [
                'grant_type' => 'refresh_token',
                "refresh_token" => $refreshToken
            ]
        ]);
        return $res;
    }


    /**
     * получения списка счетов
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function accountList()
    {
        $response = $this->send('GET', 'account/list');
        return $response;
    }

    /**
     * Создание платежа
     * @param string $account_code
     * @param string $bank_code
     * @param string $counterparty_account_number
     * @param string $counterparty_bank_bic
     * @param string $counterparty_inn
     * @param string $counterparty_name
     * @param string $payment_amount
     * @param string $payment_date
     * @param string $payment_number
     * @param string $payment_priority
     * @param string $payment_purpose
     * @param string $supplier_bill_id
     * @param string $tax_info_document_date
     * @param string $tax_info_document_number
     * @param string $tax_info_kbk
     * @param string $tax_info_okato
     * @param string $tax_info_period
     * @param string $tax_info_reason_code
     * @param string $tax_info_status
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function payment(
        $account_code = '',
        $bank_code = '',
        $counterparty_account_number = '',
        $counterparty_bank_bic = '',
        $counterparty_inn = '',
        $counterparty_name = '',
        $payment_amount = '',
        $payment_date = '',
        $payment_number = '',
        $payment_priority = '',
        $payment_purpose = '',
        $supplier_bill_id = '',
        $tax_info_document_date = '',
        $tax_info_document_number = '',
        $tax_info_kbk = '',
        $tax_info_okato = '',
        $tax_info_period = '',
        $tax_info_reason_code = '',
        $tax_info_status = '',
        $counterparty_kpp = ''
    )
    {
        $data = compact([
            "account_code",
            "bank_code",
            "counterparty_account_number",
            "counterparty_bank_bic",
            "counterparty_inn",
            "counterparty_name",
            "payment_amount",
            "payment_date",
            "payment_number",
            "payment_priority",
            "payment_purpose",
            "supplier_bill_id",
            "tax_info_document_date",
            "tax_info_document_number",
            "tax_info_kbk",
            "tax_info_okato",
            "tax_info_period",
            "tax_info_reason_code",
            "tax_info_status",
            "counterparty_kpp",
        ]);

        $data = array_filter($data, function ($val){
            return $val !== '';
        });

        return $this->send('POST', 'payment', [
            'json' => $data,
        ]);
    }
}
