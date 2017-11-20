<?php
/**
 * Created by PhpStorm.
 * User: beqa
 * Date: 11/13/17
 * Time: 12:25 PM
 */

namespace omcrn\quickbooks;

use Exception;
use QuickBooksOnline\API\DataService\DataService;
use QuickBooksOnline\API\Facades\Customer;
use QuickBooksOnline\API\Facades\Invoice;
use Yii;
use yii\base\Component;
use yii\base\InvalidConfigException;

class Quickbooks extends Component
{
    private $authMode = 'oauth2';
    private $clientID;
    private $clientSecret;
    private $accessTokenKey;
    private $refreshTokenKey;
    private $realmID;
    private $baseUrl;
    /**
     * @var DataService
     */
    private $dataService;
    public $dataServiceConfig = null;
    private $discoveryDocument;

    public function __construct($data, $config = []){
        if (!$data || !is_array($data) || !$data['clientID'] || !$data['clientSecret'] || !$data['accessTokenKey'] || !$data['refreshTokenKey'] || !$data['realmID'] || !$data['baseUrl']){
            throw new InvalidConfigException('Invalid Config');
        }
        $this->authMode = $data['authMode'];
        parent::__construct($config);
    }

    public function init()
    {
        parent::init();
        //discovery document. try catch maybe? if not accessible (0% chance) to fall back to default baseUrl. Will do later after consulting with Zura
        $this->discoveryDocument = json_decode(file_get_contents(Yii::$app->keyStorage->get('discovery_document')), true);
        if ($this->dataServiceConfig === null){
            $this->dataServiceConfig = [
                'auth_mode'         => $this->authMode,
                'ClientID'          => $this->clientID,
                'ClientSecret'      => $this->clientSecret,
                'accessTokenKey'    => $this->accessTokenKey,
                'refreshTokenKey'   => $this->refreshTokenKey,
                'QBORealmID'        => $this->realmID,
                'baseUrl'           => $this->baseUrl,
            ];
        }
        $this->dataService = DataService::Configure($this->dataServiceConfig);
    }

    static function getDataServiceConfig(){
        $ks = Yii::$app->keyStorage;
        return [
            'auth_mode' => 'oauth2',
            'ClientID' =>  $ks->get('quickbooks.client-id'),
            'ClientSecret' => $ks->get('quickbooks.client-secret'),
            'accessTokenKey' => $ks->get('quickbooks.access-token'),
            'refreshTokenKey' => $ks->get('quickbooks.refresh-token'),
            // პროდაქშენისთვის სხვა URL-ა
            'baseUrl' => $ks->get('quickbooks.base-url' . (getenv('YII_ENV') == 'dev' ? '-dev' : '')),
            'QBORealmID' => $ks->get('quickbooks.realm-id')
        ];
    }

    static function dataServiceInit(){
        // ეს ერორს არ აგდებს ტოკენს ვადაც რომ ქონდეს გასული, API CALL-ის დროს უნდა შევამოწმო ერორზე
        $dataService = DataService::Configure(self::getDataServiceConfig());
        if (!$dataService)
            exit("Problem while initializing DataService.\n");
        return $dataService;
    }

    static function reconnect(){
        $ks = Yii::$app->keyStorage;

        // discovery document. try catch maybe? if not accessible (0% chance) to fall back to default baseUrl. Will do later after consulting with Zura
        $discoveryDocument = json_decode(file_get_contents($ks->get('quickbooks.discovery_document' . (getenv('YII_ENV') == 'dev' ? '_dev' : ''))), true);

        // refresh_token თუ არ მაქვს, ესე იგი ჯერ პირველადი კონექტიც არ გამიკეტებია და რეკონექტი არ გამოვა
        $refreshToken = $ks->get('quickbooks.refresh-token');
        if (!$refreshToken){
            throw new Exception('Refresh token not set. You need to click CONNECT QUICKBOOKS button');
        }

        //გავიმზადოთ პარამეტრები. უბრალოდ წაკითხვადობისთვის, თორე მე ერთ ხაზზე მერჩია დამეწერა
        $clientId = $ks->get('quickbooks.client-id');
        $clientSecret = $ks->get('quickbooks.client-secret');

        //სადაც იგზავნება POST, მომაქვს discovery document-დან
        $tokenEndPointUrl = $discoveryDocument['token_endpoint'];

        //დოკუმენტაციის მიხედვით ტოკენის განახლების დროს აქ უნდა იყოს refresh_token
        $grantType = 'refresh_token';

        //ყველაფერი მაქვს intuit-ის დოკუმენტაციის მიხედვით
        //ვაგზავნი POST-ს და ვიღებ JSON რესპონსს
        $curl = curl_init();
        $header = [
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
            'Content-Type: application/x-www-form-urlencoded',
            'Cache-Control: no-cache'
        ];
        curl_setopt_array($curl, [
            CURLOPT_POST => 1,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => $tokenEndPointUrl,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => $grantType,
                'refresh_token' => $refreshToken
            ]),
            CURLOPT_HTTPHEADER => $header
        ]);
        $result = curl_exec($curl);
        //ყოველი შემთხვევისთვის შეცდომაზეც შევამოწმოთ
        if ($curl_error = curl_error($curl)) {
            throw new Exception($curl_error);
        } else {
            $result = json_decode($result, true);
        }
        curl_close($curl);

        // მიღებული ტოკენების შენახვა ბაზაში, key_storage_item ცხრილში
        $ks->set('quickbooks.access-token', $result['access_token']);
        $ks->set('quickbooks.access-token-expires-in', $result['expires_in']);
        $ks->set('quickbooks.refresh-token', $result['refresh_token']);
        $ks->set('quickbooks.refresh-token-expires-in', $result['x_refresh_token_expires_in']);
    }

    static function dataServiceCheckRetry(DataService $dataService, $object){
        $resultObject = $dataService->add($object);
        $error = $dataService->getLastError();
        if ($error !== null){
            $statusCode = $error->getHttpStatusCode();
            echo "The Status code is: " . $statusCode . "\n";
            echo "The Helper message is: " . $error->getOAuthHelperError() . "\n";
            echo "The Response message is: " . $error->getResponseBody() . "\n";
            if (401 == $statusCode){
                // 401 == ვადაგასულ ტოკენს, ამიტომ რეკონეკტი
                self::reconnect();
                // თავიდან ვცადოთ ობიექტის შენახვა
                $dataService = self::dataServiceInit();
                $dataService->add($object);
                // ისევ შევამოწმოთ ერორზე
                $error2 = $dataService->getLastError();
                // თუ ერორი აღარაა, დავაბრუნოთ ობიექტი, თუ არადა დავარტყათ. ისევ 401-ზე ვეღარ გავიდოდა?
                if ($error2 !== null){
                    throw new Exception($error2->getHttpStatusCode() . ' ' . $error2->getOAuthHelperError());
                }
                return $resultObject;
            }
            else{
                throw new Exception($statusCode . ' ' . $error->getOAuthHelperError());
            }
        }
        return $resultObject;
    }

    public static function createCustomer($data){
        $dataService = self::dataServiceInit();
        $customer = Customer::create($data);
        return self::dataServiceCheckRetry($dataService, $customer);
    }

    public static function createInvoice($data){
        $dataService = self::dataServiceInit();
        $invoice = Invoice::create($data);
        return self::dataServiceCheckRetry($dataService, $invoice);
    }
}