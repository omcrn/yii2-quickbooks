<?php

namespace omcrn\quickbooks;

use Exception;
use frontend\Helpers;
use QuickBooksOnline\API\Data\IPPCustomer;
use QuickBooksOnline\API\Data\IPPPaymentMethod;
use QuickBooksOnline\API\DataService\DataService;
use QuickBooksOnline\API\Facades\Account;
use QuickBooksOnline\API\Facades\Customer;
use QuickBooksOnline\API\Facades\Invoice;
use QuickBooksOnline\API\Facades\Payment;
use QuickBooksOnline\API\Facades\Item;
use Yii;
use yii\base\Component;
use yii\base\InvalidConfigException;

class Quickbooks extends Component
{
    public $authMode;
    public $realmId;
    public $baseUrl;
    public $discoveryDocumentUrl;
    public $clientId;
    public $clientSecret;
    public $oauthScope;
    public $accessToken;
    public $refreshToken;
    /**
     * @var DataService
     */
    private $dataService;


    public function init()
    {
        parent::init();
        $ks = Yii::$app->keyStorage;
        $this->accessToken = $ks->get('quickbooks.access-token');
        $this->refreshToken = $ks->get('quickbooks.refresh-token');
        $this->dataServiceInit();
    }

    private function getDataServiceConfig(){
        return [
            'auth_mode'         => $this->authMode,
            'ClientID'          => $this->clientId,
            'ClientSecret'      => $this->clientSecret,
            'baseUrl'           => $this->baseUrl,
            'QBORealmID'        => $this->realmId,
            'accessTokenKey'    => $this->accessToken,
            'refreshTokenKey'   => $this->refreshToken
        ];
    }

    public function dataServiceInit(){
        $dataService = DataService::Configure($this->getDataServiceConfig());
        if (!$dataService)
            throw new InvalidConfigException("Problem while initializing DataService.\n");
        $this->dataService = $dataService;
    }

    // two-step oauth2 process
    // tokens are saved in db and in instance and new DataService is instantiated after a successful connect
    public function connect(){
        $ks = Yii::$app->keyStorage;
        $session = Yii::$app->session;
        var_dump(Yii::$app->request->get());

        // discovery document
        $discoveryDocument = json_decode(file_get_contents($this->discoveryDocumentUrl), true);

        // ready the params, just for readability

        //სადაც იგზავნება GET პირველ ნაბიჯზე. discovery document-დან მომაქვს
        $authRequestUrl = $discoveryDocument['authorization_endpoint']; //$configs['authorizationRequestUrl'];
        //სადაც იგზავნება POST მეორე ნაბიჯზე, ესეც discovery document-დან მომაქვს
        $tokenEndPointUrl = $discoveryDocument['token_endpoint']; //$configs['tokenEndPointUrl'];

        $redirectUri = 'http://victorian-society.dev' . $ks->get('quickbooks.redirect-url');

        // დოკუმენტაციაში წერია, რომ QBO-ს მიმდინარე ვერსიისათვის ყოველთვის იქნება code, მაინც გავიტანე ცვლადში,
        // თუ ოდესმე შეიცვლება, მარტივად მივაგნებ
        $responseType = 'code';

        //დოკუმენტაციის მიხედვით აქ უნდა იყოს authorization_code
        $grantType= 'authorization_code';

        //თუ არ მოყვა code ესე იგი oauth2-ის პირველ ნაბიჯზე ვარ
        $code = Yii::$app->request->get('code');
        if (!$code)
        {
            //სესიაში შევინახოთ state მეორე ნაბიჯზე მოსულთან შესადარებლად
            $session->set('oauth_state', rand());

            $authUrl = $discoveryDocument['authorization_endpoint'] . '?client_id=' . $this->clientId .
                '&response_type=' . $responseType . '&scope=' . $this->oauthScope . '&redirect_uri=' .
                $redirectUri . '&state=' . $session->get('oauth_state');
            header("Location: ".$authUrl);
            exit();
        }
        //თუ მოყვა code ესე იგი oauth2-ის მეორე ნაბიჯზე ვარ
        else
        {
            //ამოვიღოთ state და შევადაროთ სესიაში შენახულს, თუ არ დაემთხვა, ესე იგი რაღაცა ნიტოა
            if(strcmp($session->get('oauth_state'), $_GET['state']) != 0){
                throw new Exception("The state is not correct from Intuit Server. Consider your app is hacked.");
            }

            // POST and wait for JSON response
            $curl = curl_init();
            $header = [
                'Accept: application/json',
                'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
                'Content-Type: application/x-www-form-urlencoded'
            ];
            curl_setopt_array($curl, [
                CURLOPT_POST => 1,
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_URL => $tokenEndPointUrl,
                CURLOPT_POSTFIELDS => http_build_query([
                    'grant_type' => $grantType,
                    'code' => $code,
                    'redirect_uri' => $redirectUri
                ]),
                CURLOPT_HTTPHEADER => $header
            ]);
            $result = curl_exec($curl);
            // check for errors, just in case
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
            $ks->set('quickbooks.realm-id', $_GET['realmId']);

            //დავხუროთ CONNECT-ზე დაჭერით ამომხტარი ავტორიზაციის ფანჯარა
            echo '<script type="text/javascript">
                // refresh davakomentare, cross-originebis da sxva rameebis gamo jobia im fanjaras
                // postMessage mivce da tviton mixedos tavs
                //window.opener.location.href = window.opener.location.href;
                window.opener.postMessage("success", "*");
                window.close();
            </script>';
        }
    }

    // access token expires in an hour, reconnect function revokes it using refresh token
    // refresh token lasts 101 days, after that it is necessary to click he QUICKBOOKS CONNECT button and reauthorize
    // tokens are saved in db and in instance and new DataService is instantiated after a successful reconnect
    public function reconnect(){
        $ks = Yii::$app->keyStorage;

        // if refresh_token is not set, it means the app never connected to quickbooks
        if (!$this->refreshToken){
            throw new Exception('Refresh token not set. You need to click CONNECT QUICKBOOKS button');
        }

        // discovery document
        $discoveryDocument = json_decode(file_get_contents($this->discoveryDocumentUrl), true);

        // ready the params, just for readability

        // the URL for reconnect POST, from discovery document
        $tokenEndPointUrl = $discoveryDocument['token_endpoint'];

        // according to the dcos, this param is always refresh_token for reconnect
        $grantType = 'refresh_token';

        // POST and wait for JSON response
        $curl = curl_init();
        $header = [
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
            'Content-Type: application/x-www-form-urlencoded',
            'Cache-Control: no-cache'
        ];
        curl_setopt_array($curl, [
            CURLOPT_POST => 1,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => $tokenEndPointUrl,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => $grantType,
                'refresh_token' => $this->refreshToken
            ]),
            CURLOPT_HTTPHEADER => $header
        ]);
        $result = curl_exec($curl);
        // check for errors, just in case
        if ($curl_error = curl_error($curl)) {
            throw new Exception($curl_error);
        } else {
            $result = json_decode($result, true);
        }
        curl_close($curl);
        // if reponse JSON contains "error" key, it means token is either expired or never set
        if (isset($result['error'])){
            throw new Exception($result['error'] . '. Need to click CONNECT QUICKBOOKS button');
        }

        // save the received tokens in key_storage_item and in the instance
        $this->accessToken = $result['access_token'];
        $ks->set('quickbooks.access-token', $this->accessToken);
        $ks->set('quickbooks.access-token-expires-in', $result['expires_in']);
        $this->refreshToken = $result['refresh_token'];
        $ks->set('quickbooks.refresh-token', $this->refreshToken);
        $ks->set('quickbooks.refresh-token-expires-in', $result['x_refresh_token_expires_in']);

        $this->dataServiceInit();
    }

    public function dataServiceGetObjectRetry($object, $id){
        $objects = $this->dataService->Query("select * from " . $object . " where Id='" . $id . "'");
        $error = $this->dataService->getLastError();
        if ($error){
            $statusCode = $error->getHttpStatusCode();
            if (401 == $statusCode){
                // 401 == token expired, need to reconnect
                $this->reconnect();
                // !!! recursive call
                $objects = [$this->dataServiceGetObjectRetry($object, $id)];
            }
            else{
                throw new Exception($statusCode . ' ' . $error->getOAuthHelperError());
            }
        }
        if(!empty($objects) && sizeof($objects) == 1) {
            //Helpers::dump($objects);
            //Helpers::dump(current($objects));
            return current($objects);
        }
        else{
            throw new Exception("Incorrect Query or QB Object Not found");
        }
    }

    private function dataServiceCheckRetry($object){
        $resultObject = $this->dataService->add($object);
        $error = $this->dataService->getLastError();
        if ($error){
            $statusCode = $error->getHttpStatusCode();
            if (401 == $statusCode){
                // 401 == token expired, need to reconnect
                $this->reconnect();
                // !!! recursive call
                return $this->dataServiceCheckRetry($object);
            }
            else{
                throw new Exception($statusCode . ' ' . $error->getOAuthHelperError() . ' ' . $error->getResponseBody());
            }
        }
        return $resultObject;
    }

    public function createCustomer($data){
        return $this->dataServiceCheckRetry(Customer::create($data));
    }

    public function viewCustomers($pageNumber, $pageSize){
        $allCustomers = $this->dataService->FindAll('Customer', $pageNumber, $pageSize);
        $error = $this->dataService->getLastError();
        if ($error != null) {
            echo "The Status code is: " . $error->getHttpStatusCode() . "\n";
            echo "The Helper message is: " . $error->getOAuthHelperError() . "\n";
            echo "The Response message is: " . $error->getResponseBody() . "\n";
            exit();
        }
        return $allCustomers;
    }

    public function updateCustomer($id, $data){
        $customer = $this->dataServiceGetObjectRetry("Customer", $id);
        $updatedCustomer = Customer::update($customer, $data);
        return $this->dataServiceCheckRetry($updatedCustomer);
    }

    // Turns out it is impossible to delete customer in QBO
    // Therefore I just full update it with empty fields

    // using new empty Customer will mitigate the overhead of calling SELECT prior to DELETE
    // but no, I need syncToken, so SELECT is still necessary
    // anyway, at least I don't have to manually clear all fields now
    public function deleteCustomer($id){
        $oldCustomer = $this->dataServiceGetObjectRetry("Customer", $id);
        $oldCustomer->Active = "false";
        Helpers::dump($oldCustomer);//exit;
        return $this->dataServiceCheckRetry($oldCustomer);
    }

    public function createInvoice($data){
        return $this->dataServiceCheckRetry(Invoice::create($data));
    }

    public function viewInvoices($pageNumber, $pageSize){
        $allInvoices = $this->dataService->FindAll('Invoice', $pageNumber, $pageSize);
        $error = $this->dataService->getLastError();
        if ($error != null) {
            echo "The Status code is: " . $error->getHttpStatusCode() . "\n";
            echo "The Helper message is: " . $error->getOAuthHelperError() . "\n";
            echo "The Response message is: " . $error->getResponseBody() . "\n";
            exit();
        }
        return $allInvoices;
    }

    public function createPayment($data){
        return $this->dataServiceCheckRetry(Payment::create($data));
    }

    public function viewPayments($pageNumber, $pageSize){
        $allPayments = $this->dataService->FindAll('Payment', $pageNumber, $pageSize);
        $error = $this->dataService->getLastError();
        if ($error != null) {
            echo "The Status code is: " . $error->getHttpStatusCode() . "\n";
            echo "The Helper message is: " . $error->getOAuthHelperError() . "\n";
            echo "The Response message is: " . $error->getResponseBody() . "\n";
            exit();
        }
        return $allPayments;
    }

    public function createItem($data){
        Helpers::dump($data);//exit;
        return $this->dataServiceCheckRetry(Item::create($data));
    }

    public function viewItems($pageNumber, $pageSize){
        $allItems = $this->dataService->FindAll('Item', $pageNumber, $pageSize);
        $error = $this->dataService->getLastError();
        if ($error != null) {
            echo "The Status code is: " . $error->getHttpStatusCode() . "\n";
            echo "The Helper message is: " . $error->getOAuthHelperError() . "\n";
            echo "The Response message is: " . $error->getResponseBody() . "\n";
            exit();
        }
        return $allItems;
    }

    public function createAccount($data){
        return $this->dataServiceCheckRetry(Account::create($data));
    }

    public function createPaymentMethod($data){
        $method = new IPPPaymentMethod();
        $method->domain = "QBO";
        $method->Type = $data['type'];
        $method->Active = $data['active'];
        $method->Name = $data['name'];
        return $this->dataServiceCheckRetry($method);
    }

    public function viewPaymentMethods($pageNumber, $pageSize){
        $allPaymentMethods = $this->dataService->FindAll('PaymentMethod', $pageNumber, $pageSize);
        $error = $this->dataService->getLastError();
        if ($error != null) {
            echo "The Status code is: " . $error->getHttpStatusCode() . "\n";
            echo "The Helper message is: " . $error->getOAuthHelperError() . "\n";
            echo "The Response message is: " . $error->getResponseBody() . "\n";
            exit();
        }
        return $allPaymentMethods;
    }
}